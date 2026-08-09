<x-mail::layout>
    {{-- Header --}}
    <x-slot:header>
        <x-mail::header :url="config('app.url')">
            {{ config('brand.name', config('app.name')) }}
        </x-mail::header>
    </x-slot:header>

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    {{-- Footer --}}
    <x-slot:footer>
        <x-mail::footer>
            {{ config('brand.legal_name', config('brand.name', config('app.name'))) }} - {{ date('Y') }}
            {{ config('brand.support_email', 'support@staffvora.com') }}
            @lang('This is a transactional message about your account — you are receiving it because of an action taken on your Staffvora workspace.')
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
