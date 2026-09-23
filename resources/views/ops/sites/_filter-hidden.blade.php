{{-- Bulk "all matching" actions re-run the list query server-side, so they carry every current filter. --}}
<input type="hidden" name="filter_q" value="{{ $search }}">
<input type="hidden" name="filter_channel" value="{{ $channel }}">
<input type="hidden" name="filter_status" value="{{ $status }}">
<input type="hidden" name="filter_publish" value="{{ $publish }}">
<input type="hidden" name="filter_deploy" value="{{ $deploy ?? '' }}">
<input type="hidden" name="filter_agent" value="{{ $agent ?? '' }}">
<input type="hidden" name="filter_pack" value="{{ $pack ?? '' }}">
<input type="hidden" name="filter_health" value="{{ $health ?? '' }}">
<input type="hidden" name="filter_app" value="{{ $app ?? '' }}">
<input type="hidden" name="filter_theme" value="{{ $theme ?? '' }}">
