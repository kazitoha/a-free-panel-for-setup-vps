@extends('layouts.app')

@section('title', $zone['name'].' · DNS')

@section('content')
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">powerdns · zone</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-white">
                {{ $zone['name'] }}
                @if ($zone['protected'])
                    <span class="badge badge-slate align-middle">protected</span>
                @endif
            </h1>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <span class="badge">{{ $zone['kind'] }}</span>
                <span class="mono-chip">serial {{ $zone['serial'] }}</span>
                <span class="mono-chip">{{ count($zone['records']) }} records</span>
            </div>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('dns.index') }}" class="btn-ghost">← All zones</a>
            @unless ($zone['protected'])
                <form method="POST" action="{{ route('dns.destroy', $zone['name']) }}"
                      onsubmit="return confirm('Delete zone {{ $zone['name'] }} and ALL its records?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn-ghost">Delete zone</button>
                </form>
            @endunless
        </div>
    </div>

    {{-- Add / edit record --}}
    <div class="card mb-6 p-5">
        <div class="flex items-center justify-between">
            <p class="eyebrow" id="form-title">add record</p>
            <button type="button" id="form-cancel" class="btn-ghost hidden">Cancel edit</button>
        </div>

        <form method="POST" id="record-form" action="{{ route('dns.records.store', $zone['name']) }}"
              class="mt-4 grid gap-4 sm:grid-cols-[1fr_7rem_2fr_7rem_auto] sm:items-end">
            @csrf
            <div>
                <label class="label" for="f-name">Name</label>
                <input class="input @error('name') border-rose-400 @enderror"
                       id="f-name" name="name" type="text" required
                       placeholder="@ or www or *.sub" value="{{ old('name', '@') }}">
            </div>
            <div>
                <label class="label" for="f-type">Type</label>
                <select class="input" id="f-type" name="type">
                    @foreach ($types as $type)
                        <option value="{{ $type }}" @selected(old('type', 'A') === $type)>{{ $type }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label" for="f-content">Content</label>
                <input class="input font-mono @error('content') border-rose-400 @enderror"
                       id="f-content" name="content" type="text" required
                       placeholder="203.0.113.10" value="{{ old('content') }}">
            </div>
            <div>
                <label class="label" for="f-ttl">TTL</label>
                <input class="input @error('ttl') border-rose-400 @enderror"
                       id="f-ttl" name="ttl" type="text"
                       placeholder="3600" value="{{ old('ttl', '3600') }}">
            </div>
            <button type="submit" id="form-submit" class="btn-primary">Add record</button>

            {{-- identity fields used in edit mode --}}
            <input type="hidden" id="f-old-name" name="old_name" value="">
            <input type="hidden" id="f-old-type" name="old_type" value="">
            <input type="hidden" id="f-old-content" name="old_content" value="">
        </form>

        @error('name')<p class="field-error">{{ $message }}</p>@enderror
        @error('type')<p class="field-error">{{ $message }}</p>@enderror
        @error('content')<p class="field-error">{{ $message }}</p>@enderror
        @error('ttl')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    {{-- Records --}}
    <div class="card overflow-hidden">
        <table class="table">
            <thead>
            <tr>
                <th>Name</th>
                <th>Type</th>
                <th>Content</th>
                <th>TTL</th>
                <th class="w-32 text-right">Actions</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($zone['records'] as $record)
                <tr>
                    <td class="font-mono text-xs">{{ $record['name'] }}</td>
                    <td><span class="badge {{ $record['type'] === 'SOA' ? 'badge-amber' : '' }}">{{ $record['type'] }}</span></td>
                    <td class="max-w-md truncate font-mono text-xs text-slate-400"
                        title="{{ $record['content'] }}">{{ $record['content'] }}</td>
                    <td class="font-mono text-xs text-slate-500">{{ $record['ttl'] }}</td>
                    <td>
                        <div class="flex justify-end gap-2">
                            @if ($record['editable'])
                                <button type="button" class="btn-ghost js-edit"
                                        data-name="{{ $record['name'] }}"
                                        data-type="{{ $record['type'] }}"
                                        data-content="{{ $record['content'] }}"
                                        data-ttl="{{ $record['ttl'] }}">Edit</button>
                                <form method="POST" action="{{ route('dns.records.destroy', $zone['name']) }}"
                                      onsubmit="return confirm('Delete this {{ $record['type'] }} record?');">
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="name" value="{{ $record['name'] }}">
                                    <input type="hidden" name="type" value="{{ $record['type'] }}">
                                    <input type="hidden" name="content" value="{{ $record['content'] }}">
                                    <button type="submit" class="btn-ghost">Del</button>
                                </form>
                            @else
                                <span class="text-xs text-slate-600">auto</span>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="py-10 text-center text-sm text-slate-600">
                        No records — add one above.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <script>
        (function () {
            var placeholders = {
                A: '203.0.113.10',
                AAAA: '2001:db8::1',
                CNAME: 'target.example.com',
                MX: '10 mail.example.com',
                NS: 'ns1.example.com',
                TXT: 'v=spf1 -all',
                SRV: '10 60 5060 sip.example.com',
                CAA: '0 issue "letsencrypt.org"'
            };

            var form = document.getElementById('record-form');
            var title = document.getElementById('form-title');
            var submit = document.getElementById('form-submit');
            var cancel = document.getElementById('form-cancel');
            var type = document.getElementById('f-type');
            var content = document.getElementById('f-content');
            var name = document.getElementById('f-name');
            var ttl = document.getElementById('f-ttl');
            var oldName = document.getElementById('f-old-name');
            var oldType = document.getElementById('f-old-type');
            var oldContent = document.getElementById('f-old-content');
            var updateUrl = @json(route('dns.records.update', $zone['name']));
            var storeUrl = @json(route('dns.records.store', $zone['name']));

            function placeholder() {
                content.placeholder = placeholders[type.value] || '';
            }

            function leaveEdit() {
                form.setAttribute('action', storeUrl);
                form.querySelector('[name="_method"]')?.remove();
                title.textContent = 'add record';
                submit.textContent = 'Add record';
                cancel.classList.add('hidden');
                name.value = '@';
                type.value = 'A';
                content.value = '';
                ttl.value = '3600';
                oldName.value = oldType.value = oldContent.value = '';
                placeholder();
            }

            document.querySelectorAll('.js-edit').forEach(function (button) {
                button.addEventListener('click', function () {
                    form.setAttribute('action', updateUrl);

                    if (!form.querySelector('[name="_method"]')) {
                        var method = document.createElement('input');
                        method.type = 'hidden';
                        method.name = '_method';
                        method.value = 'PUT';
                        form.appendChild(method);
                    }

                    name.value = button.dataset.name;
                    type.value = button.dataset.type;
                    content.value = button.dataset.content;
                    ttl.value = button.dataset.ttl;
                    oldName.value = button.dataset.name;
                    oldType.value = button.dataset.type;
                    oldContent.value = button.dataset.content;

                    title.textContent = 'edit record';
                    submit.textContent = 'Save record';
                    cancel.classList.remove('hidden');
                    placeholder();
                    form.scrollIntoView({behavior: 'smooth', block: 'center'});
                });
            });

            cancel.addEventListener('click', leaveEdit);
            type.addEventListener('change', placeholder);
            placeholder();
        })();
    </script>
@endsection
