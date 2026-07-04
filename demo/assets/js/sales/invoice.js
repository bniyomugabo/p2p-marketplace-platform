    $(document).ready(function () {
        $('#invoicesTable').DataTable({
            pageLength: 25,
            order: [[1, 'desc']],
            columnDefs: [
                { orderable: false, targets: [8] }
            ]
        });
    });

    function recordPayment(id, number, balance) {
        document.getElementById('payment_invoice_id').value = id;
        document.getElementById('payment_invoice_number').textContent = number;
        document.getElementById('payment_amount').max = balance;
        document.getElementById('payment_amount').value = balance;
        new bootstrap.Modal(document.getElementById('paymentModal')).show();
    }

    function cancelInvoice(id, number) {
        document.getElementById('cancel_invoice_id').value = id;
        document.getElementById('cancel_invoice_number').textContent = number;
        new bootstrap.Modal(document.getElementById('cancelModal')).show();
    }