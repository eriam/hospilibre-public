/**
 * @package Hospilibre\Facturation
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

MailFacture = {
    // Synchronise la case a cocher de la ligne avec le contenu du champ courant
    toggleAddressCheckbox: function (field) {
        const line = field.up('tr');
        line.down('td.checkbox input').checked = Boolean($V(field));
    },

    // Ajoute une ligne de saisie manuelle des lors qu'on remplit la derniere ligne libre
    addAddressLine: function (field) {
        const alreadyExpanded = field && field.hasClassName('row_added');
        const shouldAdd = !field || ($V(field) && !alreadyExpanded);

        if (!shouldAdd) {
            return;
        }

        const emailCell = DOM.td({class: 'email'}, DOM.input({
            type: 'text',
            name: 'email',
            value: '',
            onkeyup: 'MailFacture.addAddressLine(this); MailFacture.toggleAddressCheckbox(this);'
        }));
        const nameCell = DOM.td({class: 'name'}, DOM.input({type: 'text', name: 'name', value: ''}));
        const otherTag = $T('CDestinataire.tag.autre');
        const tagCell = DOM.td({class: 'tag', 'data-tag': otherTag}, otherTag);
        const checkboxCell = DOM.td({class: 'checkbox'}, DOM.input({
            type: 'checkbox',
            name: 'receivers',
            'data-object_guid': 'receiver_input'
        }));

        const newRow = DOM.tr({class: 'manual_input'});
        newRow.insert(checkboxCell);
        newRow.insert(tagCell);
        newRow.insert(nameCell);
        newRow.insert(emailCell);

        $('receivers_list').insert(newRow);

        if (field) {
            field.addClassName('row_added');
        }
    },

    send: function (form) {
        const sendButton = $('btn_send_facture_mail');
        sendButton.disable();

        const selected = $$('input[name=receivers]:checked');

        // Aucun destinataire coche : on interrompt l'envoi
        if (!selected.length) {
            alert('Veuillez choisir un destinataire.');
            sendButton.enable();
            return;
        }

        const receivers = [];
        const emails = [];
        const invalidAddresses = [];

        selected.each(function (checkbox) {
            const objectGuid = checkbox.get('object_guid');
            const row = checkbox.up('tr');

            let contactName;
            let contactEmail;
            if (row.hasClassName('manual_input')) {
                contactName = $V(row.down('td.name input'));
                contactEmail = $V(row.down('td.email input'));
            } else {
                contactName = row.down('td.name').innerHTML;
                contactEmail = row.down('td.email').innerHTML;
            }

            if (contactEmail.indexOf('@') === -1) {
                invalidAddresses.push(contactEmail);
                return;
            }

            receivers.push({
                object_guid: objectGuid,
                name: contactName.trim(),
                email: contactEmail.trim(),
                tag: row.down('td.tag').get('tag')
            });
            emails.push(contactEmail);
        });

        if (invalidAddresses.length) {
            alert('Les adresses suivantes ne sont pas valides : ' + invalidAddresses.join(', '));
            sendButton.enable();
            return;
        }

        new Url('compteRendu', 'ajax_check_whitelist')
            .addParam('emails[]', emails, true)
            .requestJSON(function (blacklisted) {
                if (blacklisted.length) {
                    alert($T('CWhiteList-alert_blacklist_addresses') + '\n' + blacklisted.join('\n'));
                    sendButton.enable();
                    return;
                }

                const target = document.body.down('#systemMsg');
                target.style.display = 'block';

                new Url('facturation', 'sendFactureByMail')
                    .addParam('receivers', Object.toJSON(receivers))
                    .addParam('subject', $V(form.elements['subject']))
                    .addParam('body', $V(form.elements['body']))
                    .addParam('facture_class', $V(form.elements['facture_class']))
                    .addParam('facture_id', $V(form.elements['facture_id']))
                    .requestUpdate(target, {
                        method: 'post',
                        getParameters: {m: 'facturation', a: 'sendFactureByMail'}
                    });

                Control.Modal.close();
            });
    },
};
