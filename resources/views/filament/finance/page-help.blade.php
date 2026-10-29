{{--
    A "How to read this page" body (P4-T37), shared by the payroll and
    compensation pages. Each group is a title, plain sentences, and optionally
    a list of terms (a button or a run kind, with what it does). Every string
    arrives already translated from the page that builds the groups.

    @var list<array{title: string, lines?: list<string>, terms?: array<string, string>}> $groups
--}}
<div class="grid min-w-0 gap-5 text-[12.5px] leading-relaxed text-gray-300 lg:grid-cols-2 xl:grid-cols-3">
    @foreach ($groups as $group)
        <section class="flex min-w-0 flex-col gap-2">
            <h3 class="text-[12px] font-semibold text-gray-50">{{ $group['title'] }}</h3>
            @foreach ($group['lines'] ?? [] as $line)
                <p>{{ $line }}</p>
            @endforeach
            @if (! empty($group['terms']))
                <dl class="flex flex-col gap-1.5">
                    @foreach ($group['terms'] as $term => $description)
                        <div>
                            <dt class="font-semibold text-gray-50">{{ $term }}</dt>
                            <dd>{{ $description }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </section>
    @endforeach
</div>
