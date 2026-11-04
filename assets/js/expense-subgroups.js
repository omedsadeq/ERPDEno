// The «لق» selects of the expense pages (includes/expense-subgroup.php). Each one
// follows the group select of its own form, and its row shows only for a group
// that has sub-groups. data-subgroups="all" lists the hidden ones too (filters);
// "active" lists the active ones, and the one an expense already has.
(function () {
    function fill(sub, selected) {
        var group = sub.form.elements[sub.dataset.groupField];
        var wanted = selected ? String(selected) : '';
        var list = (window.expenseSubgroups[group.value] || []).filter(function (s) {
            return sub.dataset.subgroups === 'all' || Number(s.is_active) === 1 || String(s.id) === wanted;
        });
        sub.innerHTML = '';
        sub.add(new Option(sub.dataset.empty, ''));
        list.forEach(function (s) { sub.add(new Option(s.name, s.id)); });
        sub.value = list.some(function (s) { return String(s.id) === wanted; }) ? wanted : '';
        sub.closest('[data-subgroup-row]').style.display = list.length ? '' : 'none';
    }

    // editExpense() sets the group from code, which fires no change event
    window.fillExpenseSubgroup = fill;

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('select[data-subgroups]').forEach(function (sub) {
            fill(sub, sub.dataset.selected);
            sub.form.elements[sub.dataset.groupField].addEventListener('change', function () { fill(sub, ''); });
        });
    });
})();
