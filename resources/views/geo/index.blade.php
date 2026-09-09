{{-- Fork feature: geo feed. See docs/fork/GEO_FEED.md --}}
@extends('layouts.app', ['title' => $title])

@push('styles')
<link href="{{ mix('css/geo.css') }}" rel="stylesheet">
@endpush

@section('content')
<geo-feed></geo-feed>
@endsection

@push('scripts')
<script type="text/javascript">window._geoConfig = @json($geoConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);</script>
<script type="text/javascript" src="{{ mix('js/geo.js') }}"></script>
<script type="text/javascript">App.boot();</script>
@endpush
