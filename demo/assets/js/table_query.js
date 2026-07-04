    $(document).ready(function () {
        $(table_id).DataTable({
            pageLength: 25,
            order: [[1, 'desc']],
            columnDefs: [
                { orderable: false, targets: [target_cols] }
            ]
        });
    });