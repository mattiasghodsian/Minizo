@php
    use App\Support\ExpiryLabel;

    /** @var \App\Models\Share $share */
    /** @var array<int, \App\Support\LibraryFile> $files */
    /** @var array<int, string> $meta */

    $isFolder = $share->type->isCollection();
@endphp

<x-layouts::public :title="$share->name" :url="$share->displayUrl()">
    <div class="flex items-start gap-6.5">
        {{-- A single shared track shows its own embedded artwork; a folder share
                    keeps the generated tile. One file means one cheap cached read to know
                    whether artwork exists, where a folder is many files with many covers and
                    picking one to stand for the whole would be a guess. --}}
        <x-ui.tile
            :name="$share->name"
            size="cover"
            variant="cover"
            :cover="($hasCover ?? false) ? route('share.cover', $share->token) : null"
            :cover-alt="__('Cover art for :name', ['name' => $share->name])"
            {{-- Known, so it renders visible with no JavaScript, which matters on a
                             page a stranger might open with scripting off. --}}
            cover-known
        />

        <div class="flex min-w-0 flex-1 flex-col gap-2.5 pt-1">
            <span class="text-brand-text text-3xs font-extrabold tracking-[1.6px]">
                {{ $share->type->kicker() }}
            </span>

            <h1 class="text-4xl leading-[1.15] font-extrabold tracking-[-.4px] text-pretty">
                {{ $share->name }}
            </h1>

            <x-ui.meta-line :parts="$meta" class="text-xs! font-semibold" />

            <div class="mt-2 flex flex-wrap items-center gap-3">
                <a
                    href="{{ route('share.download', $share->token) }}"
                    class="bg-brand text-brand-ink inline-flex items-center gap-2.5 rounded-[10px] px-5 py-3 text-sm font-extrabold transition hover:brightness-110"
                >
                    <flux:icon name="arrow-down-tray" class="size-4" />
                    {{ $share->type->downloadLabel() }}
                </a>

                {{-- Coarser than the audit table's countdown: a stranger needs to know
                                     roughly how long they have, not watch it tick. --}}
                <x-ui.pill tone="warning" class="gap-1.5 px-3.5 py-1.5">
                    <flux:icon name="clock" class="size-3" />
                    {{ __('Expires in :when', ['when' => ExpiryLabel::humanFor($share->expires_at)]) }}
                </x-ui.pill>
            </div>
        </div>
    </div>

    @if ($isFolder)
        <div class="border-hairline mt-9.5 flex flex-col border-t">
            @foreach ($files as $file)
                <div class="border-hairline/70 hover:bg-row-hover flex items-center gap-4 border-b px-2.5 py-3 transition-colors">
                    <x-ui.mono class="w-5 shrink-0">{{ $loop->iteration }}</x-ui.mono>

                    <span class="min-w-0 flex-1 truncate text-sm font-semibold">
                        {{ $file->basename() }}
                    </span>

                    <x-ui.mono variant="chip" class="shrink-0">{{ $file->formatLabel() }}</x-ui.mono>

                    <x-ui.mono class="w-[76px] shrink-0 justify-end text-end">{{ $file->sizeLabel() }}</x-ui.mono>

                    {{-- A real link, not a button: no JavaScript runs on this page, and a
                                             download should survive a middle-click. --}}
                    <a
                        href="{{ route('share.download.track', [$share->token, $file->filename]) }}"
                        class="text-ink-faint hover:text-brand-text shrink-0 rounded-sm p-[5px] transition-colors"
                        title="{{ __('Download :file', ['file' => $file->filename]) }}"
                        aria-label="{{ __('Download :file', ['file' => $file->filename]) }}"
                    >
                        <flux:icon name="arrow-down-tray" class="size-4" />
                    </a>
                </div>
            @endforeach
        </div>
    @endif

    <p class="text-ink-faint/80 mt-8.5 text-center text-xs leading-relaxed">
        {{ __('Shared from a Minizo library. The uploader can revoke this link at any time.') }}
    </p>
</x-layouts::public>
