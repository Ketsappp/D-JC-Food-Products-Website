
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('deleteHistoryModal');
    const cancelButton = document.getElementById('cancelHistoryDelete');
    const confirmButton = document.getElementById('confirmHistoryDelete');
    const message = document.getElementById('deleteHistoryMessage');

    let selectedOrderId = null;

    // Open the confirmation modal.
    document.querySelectorAll('.delete-history-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            selectedOrderId = this.dataset.orderId;

            if (message) {
                message.textContent = '';
            }

            if (modal) {
                modal.classList.add('show');
            }
        });
    });

    // Close the modal when Cancel is clicked.
    if (cancelButton) {
        cancelButton.addEventListener('click', function () {
            modal.classList.remove('show');
            selectedOrderId = null;
        });
    }

    // Close the modal if the user clicks the dark background.
    if (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                modal.classList.remove('show');
                selectedOrderId = null;
            }
        });
    }


    // Permanently delete the selected completed order.
    if (confirmButton) {
        confirmButton.addEventListener('click', async function () {
            if (!selectedOrderId) {
                return;
            }

            const orderId = selectedOrderId;

            confirmButton.disabled = true;
            cancelButton.disabled = true;

            if (message) {
                message.textContent = 'Deleting order...';
            }

            try {
                const response = await fetch('delete_order_history.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        order_id: orderId
                    })
                });

                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw new Error(
                        result.message || 'Unable to delete the order.'
                    );
                }

                // Remove the deleted order from the displayed table.
                const deleteButton = document.querySelector(
                    '.delete-history-btn[data-order-id="' + orderId + '"]'
                );

                const orderRow = deleteButton
                    ? deleteButton.closest('tr')
                    : null;

                if (orderRow) {
                    orderRow.remove();
                }

                modal.classList.remove('show');
                selectedOrderId = null;

                // Reload the page to update the empty state if necessary.
                window.location.reload();

            } catch (error) {
                if (message) {
                    message.textContent = error.message;
                } else {
                    alert(error.message);
                }
            } finally {
                confirmButton.disabled = false;
                cancelButton.disabled = false;
            }
        });
    }

});
