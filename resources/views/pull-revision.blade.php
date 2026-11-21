<input type="hidden" name="scope" value="{{ hash('sha256', $scope) }}">
<input type="hidden" name="head" value="{{ $detail['pull']['head_sha'] ?? '' }}">
<input type="hidden" name="base" value="{{ $detail['pull']['base_sha'] ?? '' }}">
