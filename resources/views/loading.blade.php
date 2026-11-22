@if (app()->isLocale('cs'))
    <script type="application/json" id="interface-messages">{!! Illuminate\Support\Js::encode(__('interface')) !!}</script>
@endif
<div id="navigation-status" role="status" class="navigation-status"></div>
<div class="loading-panel" aria-hidden="true" hidden>
    <div class="loading-columns">
        <div class="loading-files"><i></i><i></i><i></i></div>
        <div class="loading-preview"><i></i><i></i><i></i><i></i><i></i></div>
    </div>
</div>
<dialog class="project-picker" data-project-picker aria-labelledby="project-picker-title"
        data-workspace="{{ hash('sha256', (string) \Illuminate\Support\Facades\Storage::disk('local')->get('workspace.path')) }}"
        data-catalog="{{ route('workspace.catalog') }}" data-project-url="{{ route('repository') }}" data-branches-url="{{ route('repository.branches') }}">
    <div class="toolbar"><h2 id="project-picker-title">{{ __('Go to project or branch') }}</h2><button type="button" data-close-picker>{{ __('Close') }}</button></div>
    <input type="search" data-project-search aria-label="{{ __('Search projects and branches') }}" placeholder="{{ __('Search projects and branches…') }}" autocomplete="off" spellcheck="false">
    <p class="repository-meta" data-project-status role="status"></p>
    <div class="project-options" data-project-options></div>
</dialog>
