{{-- Fork feature: Immich library. See docs/fork/IMMICH.md --}}
@extends('settings.template')

@section('section')

<div class="title">
	<h3 class="font-weight-bold">Immich</h3>
	<p class="lead text-muted mb-0">Pick photos straight out of your Immich library when you post, and tag the people Immich recognised in them.</p>
</div>
<hr>
<immich-settings></immich-settings>

@endsection

@push('scripts')
<script type="text/javascript">
window._immichConfig = @json($immichConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
</script>
{{-- Before the template's settings.js, which creates the Vue root. --}}
<script type="text/javascript" src="{{ mix('js/immich.js') }}"></script>
@endpush
