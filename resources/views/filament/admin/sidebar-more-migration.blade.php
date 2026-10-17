@use('Illuminate\Support\Facades\Vite')

{{-- Run before Filament reads collapsedGroups, once per browser, without overriding later user choices. --}}
<script @if (filled(Vite::cspNonce())) nonce="{{ Vite::cspNonce() }}" @endif>
    (() => {
        const moreLabel = @js(__('navigation.more'));
        const migrationKey = 'tc-sidebar-more-v1:' + moreLabel;

        try {
            if (localStorage.getItem(migrationKey) === 'done') {
                return;
            }

            const stored = localStorage.getItem('collapsedGroups');

            if (stored !== null) {
                const groups = JSON.parse(stored);

                if (Array.isArray(groups) && ! groups.includes(moreLabel)) {
                    groups.push(moreLabel);
                    localStorage.setItem('collapsedGroups', JSON.stringify(groups));
                }
            }

            localStorage.setItem(migrationKey, 'done');
        } catch {
            // A disabled or invalid storage value must not block Filament's sidebar.
        }
    })();
</script>
