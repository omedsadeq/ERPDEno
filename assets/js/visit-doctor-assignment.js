(function () {
    function init() {
        var source = document.getElementById('doctor_assignment_source');
        var requested = document.getElementById('requested_doctor_id');
        var assigned = document.getElementById('assigned_doctor_id');
        if (!source || !requested || !assigned) return;
        function sync() {
            var selectedType = document.querySelector('input[name="visit_type"]:checked');
            var checkup = selectedType && selectedType.value === 'checkup';
            source.disabled = !checkup;
            source.required = !!checkup;
            requested.required = !!checkup && source.value === 'requested';
            assigned.required = !!checkup && source.value === 'reception';
            assigned.setCustomValidity(checkup && source.value === 'requested' && requested.value && assigned.value && assigned.value !== requested.value
                ? 'دکتۆری بینراو جیاوازە؛ گواستنەوەی پێشوازی هەڵبژێرە' : '');
        }
        source.addEventListener('change', sync);
        requested.addEventListener('change', sync);
        assigned.addEventListener('change', sync);
        document.querySelectorAll('input[name="visit_type"]').forEach(function (radio) {
            radio.addEventListener('change', sync);
        });
        sync();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
}());
