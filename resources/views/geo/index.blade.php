{{-- Fork feature: geo feed. See docs/fork/GEO_FEED.md --}}
@extends('layouts.app', ['title' => $title])

@push('styles')
{{--
	The post pane renders upstream's SPA status component, which takes its
	colours from the custom properties spa.css declares. Load it before
	geo.css, so the map's own rules still win.

	spa.css picks light or dark from prefers-color-scheme; this page was
	themed by the dark-mode cookie in layouts.app. Pin spa.css to the same
	choice — a dark post card on a light page is worse than either theme.
--}}
<link href="{{ mix('css/spa.css') }}" rel="stylesheet">
<link href="{{ mix('css/geo.css') }}" rel="stylesheet">
<script type="text/javascript">document.documentElement.classList.add(@json(request()->cookie('dark-mode') ? 'force-dark-mode' : 'force-light-mode'));</script>
@endpush

@section('content')
<geo-feed></geo-feed>
@endsection

@push('scripts')
<script type="text/javascript">
window._geoConfig = @json($geoConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
// The post pane's components read the viewer from here, as they do in the SPA.
window._sharedData.user = @json($geoUser, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
</script>
<script type="text/javascript" src="{{ mix('js/geo.js') }}"></script>
<script type="text/javascript">App.boot();</script>
@endpush
