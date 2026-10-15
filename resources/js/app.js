import './bootstrap';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('chapterUploadRecovery', (initialReady, initialFailed) => ({
        readyCount: initialReady,
        failedCount: initialFailed,
        transferItems: [],
        clientFailures: [],
        transferring: false,
        processing: false,
        saving: false,
        savingAction: null,
        pendingClientRetryId: null,

        get aggregateProgress() {
            if (this.transferItems.length === 0) return 0;

            const total = this.transferItems.reduce((sum, item) => sum + item.progress, 0);

            return Math.round(total / this.transferItems.length);
        },

        get uploadBusy() {
            return this.transferring || this.processing;
        },

        get cannotSave() {
            return this.uploadBusy
                || this.saving
                || this.readyCount === 0
                || this.failedCount > 0
                || this.clientFailures.length > 0;
        },

        get hasUnsavedWork() {
            return this.readyCount > 0
                || this.failedCount > 0
                || this.clientFailures.length > 0
                || this.uploadBusy;
        },

        selectFiles(event) {
            const files = Array.from(event.target.files || event.dataTransfer?.files || []);
            event.target.value = '';
            this.queueFiles(files);
        },

        selectServerRetry(event, failureId) {
            const file = event.target.files?.[0];
            event.target.value = '';

            if (file) this.queueFiles([file], failureId);
        },

        chooseClientRetry(failureId) {
            if (this.uploadBusy) return;

            this.pendingClientRetryId = failureId;
            this.$refs.clientRetryInput.click();
        },

        selectClientRetry(event) {
            const file = event.target.files?.[0];
            event.target.value = '';

            if (file) this.queueFiles([file], null, this.pendingClientRetryId);

            this.pendingClientRetryId = null;
        },

        queueFiles(files, serverFailureId = null, clientFailureId = null) {
            if (files.length === 0 || this.uploadBusy) return;

            const batchKey = Date.now();
            this.transferItems = files.map((file, index) => ({
                id: `${batchKey}-${index}`,
                name: file.name,
                progress: 0,
                state: 'transferring',
                clientFailureId,
            }));
            this.transferring = true;
            this.processing = false;

            files.forEach((file, index) => {
                const itemId = this.transferItems[index].id;
                const property = serverFailureId === null
                    ? 'incoming'
                    : `retryIncoming.${serverFailureId}`;

                this.$wire.$upload(
                    property,
                    file,
                    () => this.finishTransfer(itemId),
                    () => this.failTransfer(itemId, file.name),
                    event => this.progressTransfer(itemId, event.detail.progress),
                    () => this.failTransfer(itemId, file.name, 'Transfer cancelled. Select the file to retry.'),
                );
            });
        },

        progressTransfer(itemId, progress) {
            const item = this.transferItems.find(candidate => candidate.id === itemId);

            if (item) item.progress = progress;
        },

        finishTransfer(itemId) {
            const item = this.transferItems.find(candidate => candidate.id === itemId);

            if (item) {
                item.progress = 100;
                item.state = 'validating';

                if (item.clientFailureId !== null) {
                    this.dismissClient(item.clientFailureId);
                }
            }

            this.completeTransferBatchIfReady();
        },

        failTransfer(itemId, name, reason = 'Transfer failed before validation. Check the connection and file-size limit, then retry.') {
            const item = this.transferItems.find(candidate => candidate.id === itemId);

            if (item) {
                item.progress = 100;
                item.state = 'failed';
            }

            const existingId = item?.clientFailureId;
            const existing = this.clientFailures.find(failure => failure.id === existingId);

            if (existing) {
                existing.name = name;
                existing.reason = reason;
            } else {
                this.clientFailures.push({
                    id: `${Date.now()}-${Math.random()}`,
                    name,
                    reason,
                });
            }

            this.completeTransferBatchIfReady();
        },

        completeTransferBatchIfReady() {
            const complete = this.transferItems.every(item => item.state !== 'transferring');

            if (! complete) return;

            this.transferring = false;
            this.processing = this.transferItems.some(item => item.state === 'validating');

            window.setTimeout(() => {
                this.processing = false;
                this.transferItems = [];
            }, 400);
        },

        settle(detail) {
            this.readyCount = Number(detail.readyCount || 0);
            this.failedCount = Number(detail.failedCount || 0);

            if (! this.transferring) {
                this.processing = false;
                this.transferItems = [];
            }
        },

        dismissClient(failureId) {
            this.clientFailures = this.clientFailures.filter(failure => failure.id !== failureId);
        },

        beginSave(action) {
            if (this.cannotSave) return;

            this.saving = true;
            this.savingAction = action;

            this.$wire.$call(action).finally(() => {
                this.saving = false;
                this.savingAction = null;
            });
        },

        warnBeforeLeaving(event) {
            if (! this.hasUnsavedWork || this.saving) return;

            event.preventDefault();
            event.returnValue = '';
        },
    }));

    window.Alpine.data('adminDashboard', initialPreferences => ({
        hidden: [...(initialPreferences.hidden || [])],
        collapsed: [...(initialPreferences.collapsed || [])],
        dragging: null,
        dragPointerId: null,
        optionsOpen: false,
        saveState: 'Saved',
        saveTimer: null,

        isHidden(widgetId) {
            return this.hidden.includes(widgetId);
        },

        isCollapsed(widgetId) {
            return this.collapsed.includes(widgetId);
        },

        toggleCollapsed(widgetId) {
            this.collapsed = this.isCollapsed(widgetId)
                ? this.collapsed.filter(id => id !== widgetId)
                : [...this.collapsed, widgetId];

            this.persist();
        },

        setVisible(widgetId, visible) {
            this.hidden = visible
                ? this.hidden.filter(id => id !== widgetId)
                : [...new Set([...this.hidden, widgetId])];

            this.persist();
        },

        startPointerDrag(event, widgetId) {
            if (event.button !== 0) return;

            this.dragging = widgetId;
            this.dragPointerId = event.pointerId;
            event.currentTarget.setPointerCapture?.(event.pointerId);

            const widget = this.widgetNode(widgetId);

            window.requestAnimationFrame(() => {
                if (! widget) return;

                widget.classList.add('opacity-50', 'shadow-lg');
                widget.style.pointerEvents = 'none';
            });
        },

        movePointerDrag(event) {
            if (! this.dragging || event.pointerId !== this.dragPointerId) return;

            const dragged = this.widgetNode(this.dragging);
            const pointerTarget = document.elementFromPoint(event.clientX, event.clientY);
            const target = pointerTarget?.closest('[data-dashboard-widget]');
            const targetColumn = pointerTarget?.closest('[data-dashboard-column]');

            if (! dragged) return;

            if (! target) {
                if (targetColumn && targetColumn !== dragged.parentElement) targetColumn.appendChild(dragged);

                return;
            }

            if (target === dragged) return;

            const before = event.clientY < target.getBoundingClientRect().top + (target.offsetHeight / 2);
            target.parentElement.insertBefore(dragged, before ? target : target.nextSibling);
        },

        finishPointerDrag(event) {
            if (! this.dragging || event.pointerId !== this.dragPointerId) return;

            const widget = this.widgetNode(this.dragging);
            const label = widget?.dataset.widgetLabel;

            if (widget) {
                widget.classList.remove('opacity-50', 'shadow-lg');
                widget.style.pointerEvents = '';
            }

            this.dragging = null;
            this.dragPointerId = null;

            if (label) this.announce(`${label} moved. Dashboard layout saved.`);

            this.persist();
        },

        moveWithKeyboard(widgetId, direction) {
            const widget = this.widgetNode(widgetId);

            if (! widget) return;

            const column = widget.closest('[data-dashboard-column]');
            const columns = [...this.$root.querySelectorAll('[data-dashboard-column]')];
            const widgets = [...column.querySelectorAll('[data-dashboard-widget]')];
            const index = widgets.indexOf(widget);

            if (direction === 'up' && index > 0) {
                column.insertBefore(widget, widgets[index - 1]);
            } else if (direction === 'down' && index < widgets.length - 1) {
                column.insertBefore(widget, widgets[index + 1].nextSibling);
            } else if (direction === 'left' || direction === 'right') {
                const columnIndex = Number(column.dataset.dashboardColumn);
                const targetIndex = direction === 'left' ? columnIndex - 1 : columnIndex + 1;

                if (columns[targetIndex]) columns[targetIndex].appendChild(widget);
            } else {
                return;
            }

            this.$nextTick(() => widget.querySelector('[data-dashboard-handle]')?.focus());
            this.announce(`${widget.dataset.widgetLabel} moved ${direction}.`);
            this.persist();
        },

        currentColumns() {
            return [...this.$root.querySelectorAll('[data-dashboard-column]')].map(column => (
                [...column.querySelectorAll('[data-dashboard-widget]')].map(widget => widget.dataset.dashboardWidget)
            ));
        },

        persist() {
            window.clearTimeout(this.saveTimer);
            this.saveState = 'Saving…';
            this.saveTimer = window.setTimeout(() => {
                this.$wire.saveDashboardPreferences({
                    columns: this.currentColumns(),
                    hidden: this.hidden,
                    collapsed: this.collapsed,
                }).then(() => {
                    this.saveState = 'Saved';
                }).catch(() => {
                    this.saveState = 'Could not save';
                });
            }, 180);
        },

        resetLayout() {
            this.saveState = 'Resetting…';
            this.$wire.resetDashboardPreferences()
                .then(() => window.location.reload())
                .catch(() => {
                    this.saveState = 'Could not reset';
                });
        },

        closeOptions() {
            if (! this.optionsOpen) return;

            this.optionsOpen = false;
            this.$nextTick(() => this.$refs.optionsToggle.focus());
        },

        widgetNode(widgetId) {
            return this.$root.querySelector(`[data-dashboard-widget="${widgetId}"]`);
        },

        announce(message) {
            const region = this.$refs.dashboardAnnouncement;

            if (! region) return;

            region.textContent = '';
            window.requestAnimationFrame(() => {
                region.textContent = message;
            });
        },
    }));
});
