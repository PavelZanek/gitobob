@if ($detail['files'])
    <div class="review-columns">
        <nav class="review-files" aria-label="{{ __('Select a changed file') }}">
            <ul class="files">
                @foreach ($detail['files'] as $id => $file)
                    <li><a href="{{ route($detailRoute, $detailQuery + ['file' => $id]) }}#sync-diff" @if ($detail['selected'] === $file) aria-current="true" @endif>
                        <span class="file-label"><span class="filename">{{ $file->path }}</span>
                        @if ($file->originalPath !== null)<span class="file-origin">{{ __('From') }} {{ $file->originalPath }}</span>@endif</span>
                        <span class="file-states">{{ __($file->description('Sync')) }}</span>
                    </a></li>
                @endforeach
            </ul>
        </nav>
        <section id="sync-diff" class="review-diff" aria-label="{{ __('File diff') }}">
            @if ($detail['selected'])
                <div class="toolbar"><h2 class="filename">{{ $detail['selected']->path }}</h2>@isset($fileHistoryTip)<a class="button-link" href="{{ route('repository.history', ['repo' => $relative, 'tip' => $fileHistoryTip, 'path' => $detail['selected']->path]) }}">{{ __('File history') }}</a>@endisset<label class="wrap-control"><input type="checkbox" id="wrap-lines">{{ __('Wrap lines') }}</label></div>
                @include('patch', ['patch' => $detail['patch'], 'section' => 'Sync'])
            @else
                <p class="clean-workspace">{{ __('Select a file to see its diff.') }}</p>
            @endif
        </section>
    </div>
@else
    <p class="clean-workspace">{{ __('No files changed in this comparison.') }}</p>
@endif
