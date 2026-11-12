
document.addEventListener('DOMContentLoaded', function () {

    // ---- Main elements ----

    const addStaffBtn = document.getElementById('addStaffBtn');
    const staffModal = document.getElementById('staffModal');
    const staffModalTitle = document.getElementById('staffModalTitle');
    const cancelStaffModal = document.getElementById('cancelStaffModal');
    const staffForm = document.getElementById('staffForm');
    const alertBox = document.getElementById('alertBox');
    const staffFormMessage = document.getElementById('staffFormMessage');
    const staffTableBody = document.getElementById('staffTableBody');

    const staffIdInput = document.getElementById('staffId');
    const usernameInput = document.getElementById('username');
    const adminPasswordInput = document.getElementById('current_admin_password');
    const staffAddInfo = document.getElementById('staffAddInfo');
    const staffEditFields = document.getElementById('staffEditFields');
    const usernamePreview = document.getElementById('usernamePreview');
    const staffModalSub = document.getElementById('staffModalSub');
    const staffSubmit = document.getElementById('staffSubmit');
    const emailHint = document.getElementById('emailHint');

    const fullNameInput = document.getElementById('full_name');
    const contactInput = document.getElementById('contact_no');
    const emailInput = document.getElementById('email');
    const addressInput = document.getElementById('home_address');

    // ---- View staff modal ----

    const viewStaffModal = document.getElementById('viewStaffModal');
    const closeViewStaff = document.getElementById('closeViewStaff');

    const viewStaffId = document.getElementById('viewStaffId');
    const viewFullName = document.getElementById('viewFullName');
    const viewUsername = document.getElementById('viewUsername');
    const viewEmail = document.getElementById('viewEmail');
    const viewContact = document.getElementById('viewContact');
    const viewAddress = document.getElementById('viewAddress');

    // ---- Delete modal ----

    const deleteModal = document.getElementById('deleteModal');
    const cancelDelete = document.getElementById('cancelDelete');
    const confirmDelete = document.getElementById('confirmDelete');

    let deleteTargetRow = null;

    // ---- Alert messages ----

    function showAlert(message, type) {
        if (!alertBox) {
            alert(message);
            return;
        }

        alertBox.textContent = message;
        alertBox.className = 'alert-box ' + type;
        alertBox.classList.remove('hidden');

        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function showStaffFormMessage(message) {
        if (!staffFormMessage) {
            alert(message);
            return;
        }

        staffFormMessage.textContent = message;
        staffFormMessage.hidden = false;

        const body = staffModal.querySelector('.form-modal-body');

        if (body) {
            body.scrollTop = 0;
        }
    }

    function clearStaffFormMessage() {
        if (!staffFormMessage) return;

        staffFormMessage.textContent = '';
        staffFormMessage.hidden = true;
    }

    // ---- Username preview (same rule as staff_accounts.php) ----
    // First letter of the first name + the last name, letters only: "Juan Dela Cruz" -> "jcruz".

    function asciiLetters(text) {
        return text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z]/g, '');
    }

    function usernameFromName(fullName) {
        const words = fullName.trim().split(/\s+/).filter(function (word) {
            return asciiLetters(word) !== '';
        });

        // "Juan Dela Cruz Jr." -> the last name is Cruz, not Jr.
        while (words.length > 2 && ['jr', 'sr', 'ii', 'iii', 'iv'].includes(asciiLetters(words[words.length - 1]))) {
            words.pop();
        }

        if (words.length < 2) {
            return '';
        }

        return (asciiLetters(words[0]).charAt(0) + asciiLetters(words[words.length - 1])).slice(0, 30);
    }

    function updateUsernamePreview() {
        if (!usernamePreview) return;

        const preview = usernameFromName(fullNameInput.value);

        usernamePreview.textContent = preview || '\u2014';
    }

    fullNameInput.addEventListener('input', updateUsernamePreview);

    // ---- Reset and open Add/Edit modal ----
    // Add Staff only asks for the full name and e-mail. Edit Staff shows the other details.

    function setMode(isEdit) {
        staffAddInfo.hidden = isEdit;
        staffEditFields.hidden = !isEdit;

        usernameInput.required = isEdit;
        adminPasswordInput.required = isEdit;

        staffModalTitle.textContent = isEdit ? 'Edit Staff' : 'Add Staff';
        staffModalSub.textContent = isEdit
            ? 'Update this staff member\u2019s details.'
            : 'Enter their name and e-mail. The username and a temporary password are created and e-mailed to them.';
        staffSubmit.textContent = isEdit ? 'Save changes' : 'Create account';
        emailHint.hidden = isEdit;
    }

    function resetForm() {
        staffForm.reset();
        clearStaffFormMessage();

        staffIdInput.value = '';
        updateUsernamePreview();
    }

    function openModalForAdd() {
        resetForm();
        setMode(false);

        staffForm.action = 'staff_save.php';

        staffModal.classList.remove('hidden');
        fullNameInput.focus();
    }

    function openModalForEdit(btn) {
        resetForm();
        setMode(true);

        staffForm.action = 'staff_update.php';

        staffIdInput.value = btn.dataset.id || '';
        usernameInput.value = btn.dataset.username || '';
        fullNameInput.value = btn.dataset.fullname || '';
        contactInput.value = btn.dataset.contact || '';
        emailInput.value = btn.dataset.email || '';
        addressInput.value = btn.dataset.address || '';
        adminPasswordInput.value = '';

        staffModal.classList.remove('hidden');
        fullNameInput.focus();
    }

    function closeStaffModal() {
        staffModal.classList.add('hidden');
    }

    addStaffBtn.addEventListener('click', openModalForAdd);

    cancelStaffModal.addEventListener('click', function (e) {
        e.preventDefault();
        closeStaffModal();
    });

    staffModal.addEventListener('click', function (e) {
        if (e.target === staffModal) {
            closeStaffModal();
        }
    });

    // ---- View staff details ----

    function openViewModal(btn, card) {
        const staffId = btn.dataset.id || card.dataset.id || '';

        viewStaffId.textContent = staffId || '—';
        viewFullName.textContent = btn.dataset.fullname || '—';
        viewUsername.textContent = btn.dataset.username || '—';
        viewEmail.textContent = btn.dataset.email || '—';
        viewContact.textContent = btn.dataset.contact || '—';
        viewAddress.textContent = btn.dataset.address || 'Not provided';

        viewStaffModal.classList.remove('hidden');
    }

    function closeViewModal() {
        viewStaffModal.classList.add('hidden');
    }

    closeViewStaff.addEventListener('click', closeViewModal);

    viewStaffModal.addEventListener('click', function (e) {
        if (e.target === viewStaffModal) {
            closeViewModal();
        }
    });

    // ---- Staff card event handlers ----

    function attachRowEvents(card) {
        const viewBtn = card.querySelector('.view-btn');
        const editBtn = card.querySelector('.edit-btn');
        const deleteBtn = card.querySelector('.toggle-btn');

        if (viewBtn) {
            viewBtn.addEventListener('click', function () {
                openViewModal(this, card);
            });
        }

        if (editBtn) {
            editBtn.addEventListener('click', function () {
                openModalForEdit(this);
            });
        }

        if (deleteBtn) {
            deleteBtn.addEventListener('click', function () {
                deleteTargetRow = card;

                const enabling = this.dataset.active !== '1';
                document.getElementById('statusModalTitle').textContent =
                    enabling ? 'Activate this staff account?' : 'Deactivate this staff account?';
                document.getElementById('statusModalText').textContent = enabling
                    ? 'They will be able to log in again.'
                    : 'They will no longer be able to log in. You can activate the account again anytime.';

                deleteModal.classList.remove('hidden');
            });
        }
    }

    if (staffTableBody) {
        staffTableBody.querySelectorAll('.staff-card').forEach(function (card) {
            attachRowEvents(card);
        });
    }

    // ---- Disable / Enable staff ----

    confirmDelete.addEventListener('click', function () {
        if (!deleteTargetRow) {
            return;
        }

        const card = deleteTargetRow;
        const toggleBtn = card.querySelector('.toggle-btn');
        const enabling = toggleBtn.dataset.active !== '1';
        const formData = new FormData();

        formData.append('id', card.dataset.id);
        formData.append('action', enabling ? 'enable' : 'disable');

        confirmDelete.disabled = true;

        fetch('staff_toggle.php', {
            method: 'POST',
            body: formData
        })
        .then(function (response) {
            return response.text();
        })
        .then(function (data) {
            if (data.includes('successfully.')) {
                applyStatus(card, enabling);
                deleteTargetRow = null;
                deleteModal.classList.add('hidden');
                showAlert(enabling ? 'Account activated.' : 'Account deactivated.', 'success');
            } else {
                alert(data);
            }
        })
        .catch(function (error) {
            console.error(error);
            alert('An error occurred while updating the staff account.');
        })
        .finally(function () {
            confirmDelete.disabled = false;
        });
    });

    function applyStatus(card, active) {
        const btn = card.querySelector('.toggle-btn');
        const badge = card.querySelector('.status-badge');

        card.classList.toggle('is-disabled', !active);
        btn.dataset.active = active ? '1' : '0';
        btn.classList.toggle('disable-btn', active);
        btn.classList.toggle('enable-btn', !active);
        btn.title = active ? 'Deactivate this account' : 'Activate this account';
        btn.innerHTML = active ? '&#9940; Deactivate' : '&#10003; Activate';
        badge.textContent = active ? 'Active' : 'Inactive';
        badge.classList.toggle('is-enabled', active);
        badge.classList.toggle('is-off', !active);
    }

    cancelDelete.addEventListener('click', function (e) {
        e.preventDefault();

        deleteModal.classList.add('hidden');
        deleteTargetRow = null;
    });

    deleteModal.addEventListener('click', function (e) {
        if (e.target === deleteModal) {
            deleteModal.classList.add('hidden');
            deleteTargetRow = null;
        }
    });

    // ---- Validate and save staff ----

    staffForm.addEventListener('submit', function (e) {
        e.preventDefault();
        clearStaffFormMessage();

        const isEdit = staffIdInput.value !== '';
        const fullName = fullNameInput.value.trim().replace(/\s+/g, ' ');
        const email = emailInput.value.trim();

        function warn(message, input) {
            showStaffFormMessage(message);

            if (input) {
                input.focus();
            }
        }

        if (!fullName) {
            warn('Please enter the staff member\u2019s full name.', fullNameInput);
            return;
        }

        // Add Staff builds the username from the first and last name.
        if (!isEdit && !usernameFromName(fullName)) {
            warn('Please enter the first name and the last name (for example: Juan Dela Cruz).', fullNameInput);
            return;
        }

        if (!emailInput.validity.valid || email === '') {
            warn('Please enter a valid email address, such as staff@gmail.com.', emailInput);
            return;
        }

        let formData;

        if (isEdit) {
            const phone = contactInput.value.trim();

            if (!usernameInput.value.trim()) {
                warn('Please enter a username.', usernameInput);
                return;
            }

            if (phone !== '' && !/^(09[0-9]{9}|\+639[0-9]{9})$/.test(phone)) {
                warn('Please enter a valid Philippine mobile number, such as 09171234567 or +639171234567.', contactInput);
                return;
            }

            if (!adminPasswordInput.value) {
                warn('Please enter your admin password to save these changes.', adminPasswordInput);
                return;
            }

            formData = new FormData(staffForm);
            formData.set('full_name', fullName);
            formData.set('email', email);
            formData.set('contact_no', phone);
            formData.set('home_address', addressInput.value.trim());
        } else {
            // Only the name and e-mail are sent; the server creates the username and the temporary password.
            formData = new FormData();
            formData.set('full_name', fullName);
            formData.set('email', email);
        }

        const url = isEdit ? 'staff_update.php' : 'staff_save.php';

        staffSubmit.disabled = true;

        fetch(url, {
            method: 'POST',
            body: formData
        })
        .then(function (response) {
            return response.text();
        })
        .then(function (data) {
            const message = data.trim();

            if (
                message.includes('Staff account created successfully.') ||
                message.includes('Staff updated successfully.') ||
                message.includes('No changes were made.')
            ) {
                closeStaffModal();

                showAlert(message, 'success');

                setTimeout(function () {
                    window.location.reload();
                }, 1800);
            } else {
                showStaffFormMessage(
                    message || 'Unable to save the staff account.'
                );
            }
        })
        .catch(function (error) {
            console.error(error);

            showStaffFormMessage(
                'An error occurred while saving the staff account. Please try again.'
            );
        })
        .finally(function () {
            staffSubmit.disabled = false;
        });
    });

});
