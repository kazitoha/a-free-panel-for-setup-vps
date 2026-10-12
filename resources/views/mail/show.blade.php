@extends('layouts.app')

@section('title', $domain['name'].' · Email')

@section('content')
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">stalwart · domain</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-white">
                {{ $domain['name'] }}
                @unless ($domain['enabled'])
                    <span class="badge badge-slate align-middle">disabled</span>
                @endunless
            </h1>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <span class="mono-chip">MX → {{ $hostname }}</span>
                <span class="mono-chip">{{ count($mailboxes) }} mailboxes</span>
            </div>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('mail.index') }}" class="btn-ghost">← All domains</a>
            <form method="POST" action="{{ route('mail.destroy', $domain['id']) }}"
                  onsubmit="return confirm('Delete {{ $domain['name'] }} and ALL its mailboxes?');">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn-ghost">Delete domain</button>
            </form>
        </div>
    </div>

    <div class="card mb-6 p-5">
        <p class="eyebrow">mail client settings</p>
        <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="text-slate-500">Username</dt>
                <dd class="mt-1 font-mono text-slate-300">full email address</dd>
            </div>
            <div>
                <dt class="text-slate-500">Incoming · IMAP</dt>
                <dd class="mt-1 font-mono text-slate-300">{{ $hostname }}:993 · SSL/TLS</dd>
            </div>
            <div>
                <dt class="text-slate-500">Outgoing · SMTP</dt>
                <dd class="mt-1 font-mono text-slate-300">{{ $hostname }}:465 · SSL/TLS</dd>
            </div>
            <div>
                <dt class="text-slate-500">Alternative SMTP</dt>
                <dd class="mt-1 font-mono text-slate-300">{{ $hostname }}:587 · STARTTLS</dd>
            </div>
        </dl>
        <p class="mt-4 rounded-md border border-slate-800 bg-[#0a101c] p-3 text-xs leading-relaxed text-slate-500">
            Use the mailbox password for both protocols. External mail requires this domain's nameservers to be delegated to the panel and your VPS provider to allow port 25. Client submission uses 465 or 587.
        </p>
    </div>

    {{-- Create mailbox --}}
    <div class="card mb-6 p-5">
        <p class="eyebrow">new mailbox</p>

        <form method="POST" action="{{ route('mail.mailboxes.store', $domain['id']) }}"
              class="mt-4 grid gap-4 sm:grid-cols-[1fr_1.5fr_1.5fr_auto] sm:items-end">
            @csrf
            <div>
                <label class="label" for="f-name">Name</label>
                <input class="input @error('name') border-rose-400 @enderror"
                       id="f-name" name="name" type="text" required
                       placeholder="hello" value="{{ old('name') }}">
            </div>
            <div>
                <label class="label" for="f-password">Password</label>
                <input class="input @error('password') border-rose-400 @enderror"
                       id="f-password" name="password" type="password" required
                       minlength="8" autocomplete="new-password" placeholder="At least 8 characters">
            </div>
            <div>
                <label class="label" for="f-description">Description</label>
                <input class="input @error('description') border-rose-400 @enderror"
                       id="f-description" name="description" type="text"
                       placeholder="Sales inbox" value="{{ old('description') }}">
            </div>
            <button type="submit" class="btn-primary">Create</button>
        </form>

        <p class="mt-3 font-mono text-xs text-slate-500">
            Address: <span class="text-slate-400">{{ old('name', 'name').'@'.$domain['name'] }}</span>
        </p>

        @error('name')<p class="field-error">{{ $message }}</p>@enderror
        @error('password')<p class="field-error">{{ $message }}</p>@enderror
        @error('description')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    {{-- Mailboxes --}}
    <div class="card overflow-hidden">
        <table class="table">
            <thead>
            <tr>
                <th>Mailbox</th>
                <th>Description</th>
                <th>Status</th>
                <th class="w-44 text-right">Actions</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($mailboxes as $mailbox)
                <tr>
                    <td class="font-mono text-xs text-slate-300">{{ $mailbox['name'].'@'.$domain['name'] }}</td>
                    <td class="max-w-xs truncate text-sm text-slate-500"
                        title="{{ $mailbox['description'] }}">{{ $mailbox['description'] ?: '—' }}</td>
                    <td>
                        @if ($mailbox['enabled'])
                            <span class="badge badge-green">active</span>
                        @else
                            <span class="badge badge-slate">disabled</span>
                        @endif
                    </td>
                    <td>
                        <div class="flex justify-end gap-2">
                            <button type="button" class="btn-ghost js-reset"
                                    data-name="{{ $mailbox['name'] }}"
                                    data-url="{{ route('mail.mailboxes.update', [$domain['id'], $mailbox['id']]) }}">Reset</button>
                            <form method="POST"
                                  action="{{ route('mail.mailboxes.destroy', [$domain['id'], $mailbox['id']]) }}"
                                  onsubmit="return confirm('Delete {{ $mailbox['name'].'@'.$domain['name'] }}?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-ghost">Del</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="py-10 text-center text-sm text-slate-600">
                        No mailboxes — create the first one above.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <script>
        (function () {
            document.querySelectorAll('.js-reset').forEach(function (button) {
                button.addEventListener('click', function () {
                    var name = button.dataset.name;
                    var password = prompt('New password for ' + name + '@' + @json($domain['name']) + ':');
                    if (password === null) return;

                    var form = document.createElement('form');
                    form.method = 'POST';
                    form.action = button.dataset.url;

                    var csrf = document.createElement('input');
                    csrf.type = 'hidden';
                    csrf.name = '_token';
                    csrf.value = document.querySelector('meta[name="csrf-token"]').content;
                    form.appendChild(csrf);

                    var method = document.createElement('input');
                    method.type = 'hidden';
                    method.name = '_method';
                    method.value = 'PUT';
                    form.appendChild(method);

                    var field = document.createElement('input');
                    field.type = 'hidden';
                    field.name = 'password';
                    field.value = password;
                    form.appendChild(field);

                    document.body.appendChild(form);
                    form.submit();
                });
            });
        })();
    </script>
@endsection
