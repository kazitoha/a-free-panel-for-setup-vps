@if (session('success'))
    <div class="mb-6 flex items-start gap-3 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300"
         role="status">
        <span aria-hidden="true">✔</span>
        <p>{{ session('success') }}</p>
    </div>
@endif

@if (session('error'))
    <div class="mb-6 flex items-start gap-3 rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-300"
         role="alert">
        <span aria-hidden="true">✖</span>
        <p>{{ session('error') }}</p>
    </div>
@endif

@if ($errors->any())
    <div class="mb-6 flex items-start gap-3 rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-300"
         role="alert">
        <span aria-hidden="true">✖</span>
        <div>
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    </div>
@endif
