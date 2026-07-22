@if(session('success') || session('error') || session('warning') || $errors->any())
    <div class="sf-container sf-flashes">
        @foreach(['success' => 'success', 'error' => 'error', 'warning' => 'warning'] as $key => $type)
            @if(session($key))
                <div class="sf-flash sf-flash--{{ $type }}" role="alert">{{ session($key) }}</div>
            @endif
        @endforeach
        @if($errors->any())
            <div class="sf-flash sf-flash--error" role="alert">{{ __('storefront.validate_form') }}</div>
        @endif
    </div>
@endif
