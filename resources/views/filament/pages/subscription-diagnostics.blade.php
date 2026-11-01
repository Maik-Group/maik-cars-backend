<x-filament-panels::page>
    <x-filament::section heading="Subscription operations">
        <dl class="grid gap-4 md:grid-cols-2">
            @foreach ($this->summary() as $label => $count)
                <div><dt>{{ $label }}</dt><dd class="text-2xl font-semibold">{{ $count }}</dd></div>
            @endforeach
        </dl>
    </x-filament::section>
    {{ $this->table }}
</x-filament-panels::page>
