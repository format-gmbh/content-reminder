/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

/**
 * Actions for reminders in the backend (page module panel, later list module and dashboard):
 * - [data-content-reminder-action="complete|takeOver|pause|resume|reopen"][data-uid]
 * - [data-content-reminder-history="<ajax url>"] opens the history modal
 */
import { html } from 'lit';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import RegularEvent from '@typo3/core/event/regular-event.js';
import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';

// Labels come from TYPO3.lang (page and list module) or, in dashboard widgets loaded
// via AJAX, from a data-content-reminder-labels attribute
const label = (key) => {
  const fullKey = 'js.' + key;
  if (window.TYPO3?.lang?.[fullKey]) {
    return TYPO3.lang[fullKey];
  }
  for (const element of document.querySelectorAll('[data-content-reminder-labels]')) {
    try {
      const labels = JSON.parse(element.dataset.contentReminderLabels);
      if (labels[fullKey]) {
        return labels[fullKey];
      }
    } catch {
      // ignore invalid label data
    }
  }
  return key;
};

class ReminderActions {
  constructor() {
    new RegularEvent('click', (event, target) => {
      event.preventDefault();
      this.handleAction(target);
    }).delegateTo(document, '[data-content-reminder-action]');

    new RegularEvent('click', (event, target) => {
      event.preventDefault();
      this.showHistory(target.dataset.contentReminderHistory);
    }).delegateTo(document, '[data-content-reminder-history]');
  }

  handleAction(button) {
    const action = button.dataset.contentReminderAction;
    if (action === 'complete') {
      this.confirmComplete(button);
      return;
    }
    this.send({ action, uid: button.dataset.uid });
  }

  confirmComplete(button) {
    const hint = label(button.dataset.recurring === '1' ? 'complete.hintRecurring' : 'complete.hint')
      .replace('%s', button.dataset.title ?? '');
    Modal.advanced({
      title: label('complete.title'),
      severity: SeverityEnum.notice,
      content: html`
        <p>${hint}</p>
        <label class="form-label" for="content-reminder-comment">${label('complete.comment')}</label>
        <textarea class="form-control" id="content-reminder-comment" rows="3"></textarea>
      `,
      buttons: [
        {
          text: label('cancel'),
          btnClass: 'btn-default',
          name: 'cancel',
          trigger: (event, modal) => modal.hideModal(),
        },
        {
          text: label('complete.confirm'),
          btnClass: 'btn-primary',
          name: 'complete',
          active: true,
          trigger: (event, modal) => {
            const comment = modal.querySelector('#content-reminder-comment')?.value ?? '';
            modal.hideModal();
            this.send({ action: 'complete', uid: button.dataset.uid, comment });
          },
        },
      ],
    });
  }

  showHistory(url) {
    Modal.advanced({
      type: Modal.types.ajax,
      title: label('history.title'),
      content: url,
      size: Modal.sizes.large,
      severity: SeverityEnum.notice,
      buttons: [
        {
          text: label('close'),
          btnClass: 'btn-default',
          name: 'close',
          active: true,
          trigger: (event, modal) => modal.hideModal(),
        },
      ],
    });
  }

  async send(data) {
    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.content_reminder_action).post(data);
      const result = await response.resolve();
      Notification.success(label('notification.success'), result.message, 3);
      this.refresh();
    } catch (error) {
      let message = label('notification.failed');
      try {
        const result = await error.resolve();
        message = result.message || message;
      } catch {
        // keep generic message
      }
      Notification.error(label('notification.error'), message);
    }
  }

  refresh() {
    // Update the page tree markers and the panel
    top.document.dispatchEvent(new CustomEvent('typo3:pagetree:refresh'));
    window.location.reload();
  }
}

export default new ReminderActions();
