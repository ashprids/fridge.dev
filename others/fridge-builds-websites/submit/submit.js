function initWebsiteCommissionForm() {
    const form = document.querySelector('.commission-form');
    if (!form || form.dataset.commissionBound === '1') return;
    form.dataset.commissionBound = '1';

    const submitButton = form.querySelector('button[type="submit"]');
    const signatureInput = form.querySelector('input[name="signature"]');
    const signatureStatus = form.querySelector('.commission-signature-status');
    const signatureName = form.querySelector('[data-commission-signature-name]');
    const setConditionalState = (target, visible) => {
        if (!target) return;
        target.hidden = !visible;
        const input = target.querySelector('input, textarea');
        if (input) input.required = visible;
    };

    const updateConditionalFields = () => {
        form.querySelectorAll('[data-conditional-group]').forEach(group => {
            const name = group.dataset.conditionalGroup;
            const selected = form.querySelector(`input[name="${name}"]:checked`);
            setConditionalState(
                document.getElementById(group.dataset.conditionalTarget),
                selected?.value === group.dataset.conditionalValue
            );
        });

        form.querySelectorAll('[data-checkbox-other]').forEach(group => {
            const checkbox = form.querySelector('input[name="website_types[]"][value="other"]');
            setConditionalState(document.getElementById(group.dataset.conditionalTarget), !!checkbox?.checked);
        });
    };

    const updateSubmitState = () => {
        if (!submitButton) return;
        const agreed = form.querySelector('input[name="terms_agreed"]:checked')?.value === 'yes';
        const signed = !!signatureInput?.value.trim();
        const serverDisabled = submitButton.dataset.serverDisabled === '1';
        const websiteTypes = Array.from(form.querySelectorAll('input[name="website_types[]"]'));
        if (websiteTypes[0]) {
            websiteTypes[0].setCustomValidity(websiteTypes.some(input => input.checked) ? '' : 'Choose at least one website type.');
        }
        submitButton.disabled = serverDisabled || !agreed || !signed;
        submitButton.setAttribute('aria-disabled', submitButton.disabled ? 'true' : 'false');
        submitButton.classList.toggle('form-button-disabled', submitButton.disabled);
        if (!serverDisabled) {
            submitButton.setAttribute('data-tooltip', agreed && signed
                ? 'submit this website commission request'
                : 'agree to and sign the commission terms to submit');
        }
    };

    const clearSignature = () => {
        if (signatureInput) signatureInput.value = '';
        if (signatureName) signatureName.textContent = '';
        if (signatureStatus) signatureStatus.hidden = true;
    };

    const setSignature = signature => {
        if (signatureInput) signatureInput.value = signature;
        if (signatureName) signatureName.textContent = signature;
        if (signatureStatus) signatureStatus.hidden = false;
    };

    form.addEventListener('change', async event => {
        updateConditionalFields();
        updateSubmitState();

        const target = event.target;
        if (!(target instanceof HTMLInputElement) || target.name !== 'terms_agreed') return;
        if (target.value !== 'yes' || !target.checked) {
            clearSignature();
            updateSubmitState();
            return;
        }
        if (signatureInput?.value.trim()) return;

        const result = await window.showSitePopup({
            title: 'Signature',
            detail: 'Sign your name here to declare that you agree to the commission terms.',
            input: true,
            inputPlaceholder: 'Your name',
            cancelText: 'Cancel',
            okText: 'Sign'
        });
        const signature = typeof result === 'string' ? result.trim() : '';
        if (signature && signature.length <= 120) {
            setSignature(signature);
        } else {
            target.checked = false;
            clearSignature();
            if (signature.length > 120) {
                await window.showSitePopup({
                    title: 'Signature',
                    detail: 'Your signature must be 120 characters or less.',
                    okText: 'OK'
                });
            }
        }
        updateSubmitState();
    });

    const cooldownUntil = Number(submitButton?.dataset.commissionCooldownUntil || 0) * 1000;
    if (submitButton && cooldownUntil > 0) {
        const updateCooldown = () => {
            const remaining = Math.max(0, Math.ceil((cooldownUntil - Date.now()) / 1000));
            if (remaining === 0) {
                submitButton.dataset.serverDisabled = '0';
                submitButton.removeAttribute('data-commission-cooldown-until');
                submitButton.textContent = 'submit';
                submitButton.setAttribute('data-tooltip', 'submit this website commission request');
                updateSubmitState();
                return;
            }
            const hours = String(Math.floor(remaining / 3600)).padStart(2, '0');
            const minutes = String(Math.floor((remaining % 3600) / 60)).padStart(2, '0');
            const seconds = String(remaining % 60).padStart(2, '0');
            submitButton.textContent = `${hours}:${minutes}:${seconds}`;
            window.setTimeout(updateCooldown, 250);
        };
        updateCooldown();
    }

    updateConditionalFields();
    updateSubmitState();
}

window.fridgeInitWebsiteCommissionForm = initWebsiteCommissionForm;
initWebsiteCommissionForm();
