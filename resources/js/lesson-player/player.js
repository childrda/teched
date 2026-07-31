import { createActiveTimeTracker } from './active-time';
import { createAutosaveController } from './autosave';
import { createCompletionRegistry } from './completion';
import { createSpeechController, RATE } from './speech';

/**
 * The student player, as an Alpine component.
 *
 * Navigation is a change of index rather than a request. Attempt restore,
 * autosave, and active-time tracking keep work durable across reloads.
 *
 * Controllers pass an explicit capabilities object. Modules branch on those
 * flags only — never on preview mode, attempt presence, or grading_token.
 */
export function lessonPlayer(manifest, attempt = null, capabilities = null) {
  const restore = attempt && typeof attempt === 'object' ? attempt : null;
  const readOnly = restore?.read_only === true || restore?.status === 'completed';
  const caps = {
    canPersist: true,
    canGrade: true,
    canAdvancePersistently: true,
    bypassCompletionGates: false,
    ...(capabilities && typeof capabilities === 'object' ? capabilities : {}),
  };

  return {
    manifest,
    attempt: restore,
    readOnly,
    capabilities: caps,
    pages: Array.isArray(manifest?.pages) ? manifest.pages : [],
    currentIndex: 0,
    attemptRevision: restore?.revision ?? 0,
    announcement: '',
    settingsOpen: false,
    syncStatus: 'saved',
    syncMessage: '',
    conflicted: false,

    /**
     * Bumped whenever the set of contributors changes, so the Continue gate
     * recomputes when a renderer registers or drops one. Changes to whether
     * a contributor is satisfied need no counter: reading its state inside
     * the gate is what makes the gate depend on it.
     */
    registryVersion: 0,
    syncVersion: 0,

    completion: createCompletionRegistry(),
    speechController: null,
    autosave: null,
    activeTime: null,

    speech: {
      supported: false,
      speaking: false,
      paused: false,
      activeBlockId: null,
      activeSegmentId: null,
      rate: RATE.default,
      voiceUri: '',
      voices: [],
    },

    init() {
      this.speechController = createSpeechController(this.speech, {
        onSegmentChange: (blockId, segmentId) => this.highlightSegment(blockId, segmentId),
      });

      this.speechController.init();

      if (restore?.current_page_id) {
        const index = this.pages.findIndex((page) => page.page_id === restore.current_page_id);

        if (index >= 0) {
          this.currentIndex = index;
        }
      }

      // When canPersist is false (preview), never touch localStorage autosave
      // keys and never start active-time timers — shared Chromebook carts.
      if (this.capabilities.canPersist && restore?.id && ! this.readOnly) {
        const getCsrf = () =>
          document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

        this.autosave = createAutosaveController({
          attemptId: restore.id,
          getCsrf,
          onStatus: (status, detail = {}) => {
            if (status === 'announce') {
              this.announceSync(detail.status);

              return;
            }

            if (status === 'conflict') {
              this.conflicted = true;
              this.syncStatus = 'conflict';
              this.syncMessage = this.strings.conflict;
              this.syncVersion += 1;

              return;
            }

            this.syncStatus = status;
            this.syncMessage = this.strings[status] ?? '';
            this.syncVersion += 1;
          },
        });

        for (const row of restore.block_states ?? []) {
          this.autosave.setAcknowledged(row.block_id, row.revision ?? 0);
        }

        this.autosave.replayPending();

        this.activeTime = createActiveTimeTracker({
          attemptId: restore.id,
          getCsrf,
        });
        this.activeTime.start();

        this._onVisibility = () => {
          if (document.visibilityState === 'hidden') {
            void this.autosave?.flushAll({ keepalive: true });
            void this.activeTime?.flush({ keepalive: true });
          }
        };
        this._onPageHide = () => {
          void this.autosave?.flushAll({ keepalive: true });
          void this.activeTime?.flush({ keepalive: true });
        };

        document.addEventListener('visibilitychange', this._onVisibility);
        window.addEventListener('pagehide', this._onPageHide);
      }
    },

    get strings() {
      return {
        saving: 'Saving',
        saved: 'Saved',
        pending: 'Not yet synced',
        conflict: 'This lesson is open somewhere else. Reload the page to continue.',
      };
    },

    _onVisibility: null,
    _onPageHide: null,

    destroy() {
      this.speechController?.destroy();
      this.autosave?.destroy();
      this.activeTime?.destroy();
      document.removeEventListener('visibilitychange', this._onVisibility);
      window.removeEventListener('pagehide', this._onPageHide);
    },

    get totalPages() {
      return this.pages.length;
    },

    get currentPage() {
      return this.pages[this.currentIndex] ?? null;
    },

    get pageSettings() {
      return this.currentPage?.settings ?? {};
    },

    get isLastPage() {
      return this.currentIndex >= this.totalPages - 1;
    },

    get progressPercent() {
      if (this.totalPages === 0) {
        return 0;
      }

      return Math.round(((this.currentIndex + 1) / this.totalPages) * 100);
    },

    get canGoBack() {
      return this.currentIndex > 0 && this.pageSettings.allow_back_navigation !== false;
    },

    get allowSkip() {
      return this.pageSettings.allow_skip === true && ! this.readOnly;
    },

    get completionState() {
      void this.registryVersion;

      const page = this.currentPage;

      if (page === null) {
        return { satisfied: true, message: null };
      }

      return this.completion.evaluate(page.page_id, page.completion_type, { shown: true });
    },

    get pageBlockIds() {
      return (this.currentPage?.blocks ?? []).map((block) => block.block_id);
    },

    get pageSynced() {
      void this.syncVersion;

      if (this.readOnly || ! this.autosave) {
        return true;
      }

      if (this.conflicted) {
        return false;
      }

      return this.autosave.isPageSynced(this.pageBlockIds);
    },

    get canContinue() {
      if (this.readOnly && ! this.capabilities.bypassCompletionGates) {
        return false;
      }

      if (this.capabilities.bypassCompletionGates) {
        return ! this.isLastPage;
      }

      return this.completionState.satisfied && this.pageSynced && ! this.conflicted;
    },

    get continueLabel() {
      if (! this.capabilities.canAdvancePersistently) {
        return 'Next page (preview)';
      }

      return this.isLastPage ? 'Finish' : 'Continue';
    },

    /** Why Continue is unavailable, or '' when it is available. */
    get gateMessage() {
      if (this.readOnly && ! this.capabilities.bypassCompletionGates) {
        return 'This lesson is complete. Your answers are shown read-only.';
      }

      if (this.capabilities.bypassCompletionGates) {
        return this.isLastPage ? 'End of preview.' : '';
      }

      if (this.conflicted) {
        return this.strings.conflict;
      }

      if (! this.pageSynced) {
        return this.strings.pending;
      }

      const state = this.completionState;

      return state.satisfied ? '' : (state.message ?? '');
    },

    stateFor(blockId) {
      const row = (this.attempt?.block_states ?? []).find((entry) => entry.block_id === blockId);

      return row?.state ?? null;
    },

    revisionFor(blockId) {
      return this.autosave?.getAcknowledged(blockId) ?? this.revisionFromRestore(blockId);
    },

    revisionFromRestore(blockId) {
      const row = (this.attempt?.block_states ?? []).find((entry) => entry.block_id === blockId);

      return row?.revision ?? 0;
    },

    submissionFor(blockId) {
      return this.attempt?.submissions?.[blockId] ?? null;
    },

    /** Student-safe manual review for short_response/cer (latest submission only). */
    reviewFor(blockId) {
      return this.attempt?.reviews?.[blockId] ?? null;
    },

    shuffleSeed() {
      return this.attempt?.shuffle_seed ?? '';
    },

    queueSave(blockId, state) {
      if (! this.capabilities.canPersist || this.readOnly || ! this.autosave) {
        return;
      }

      this.autosave.queue(blockId, state, this.autosave.getAcknowledged(blockId));
    },

    /**
     * Called from a renderer's x-init; see completion.js for the shape.
     *
     * Returns nothing on purpose. Alpine calls any function an expression
     * evaluates to, so handing back the registry's own remove handle would
     * unregister the contributor the moment it registered.
     */
    registerContributor(pageId, contributor) {
      this.completion.register(pageId, contributor);

      this.registryVersion += 1;
    },

    /**
     * Registration for a renderer that tears itself down: unlike
     * registerContributor(), this hands the registry's remove handle back.
     * Call it from somewhere that stores the handle — an x-init expression
     * that merely evaluates to it would have Alpine call it immediately.
     */
    addContributor(pageId, contributor) {
      const remove = this.completion.register(pageId, contributor);

      this.registryVersion += 1;

      return () => {
        remove();

        this.registryVersion += 1;
      };
    },

    async goForward() {
      if (this.isLastPage) {
        return;
      }

      if (this.readOnly && ! this.capabilities.bypassCompletionGates) {
        return;
      }

      if (! this.canContinue) {
        this.announce(this.gateMessage);

        return;
      }

      // Preview / non-persistent advance: local index only — never the
      // persisted current_page_id Continue endpoint.
      if (! this.capabilities.canAdvancePersistently) {
        this.goTo(this.currentIndex + 1);

        return;
      }

      if (this.autosave) {
        for (const blockId of this.pageBlockIds) {
          this.autosave.cancelDebounce(blockId);
        }

        const synced = await this.autosave.flushAll();

        if (! synced || ! this.pageSynced) {
          this.announce(this.strings.pending);

          return;
        }
      }

      // Without an attempt (unit tests, or a misconfigured embed) navigate
      // locally — the live player always has restore data from the server.
      if (! this.attempt?.id) {
        this.goTo(this.currentIndex + 1);

        return;
      }

      const pageId = this.currentPage?.page_id;
      const csrf =
        typeof document !== 'undefined'
          ? (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '')
          : '';

      try {
        const response = await fetch(
          `/player/attempts/${encodeURIComponent(this.attempt.id)}/pages/${encodeURIComponent(pageId)}/continue`,
          {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              Accept: 'application/json',
              'X-CSRF-TOKEN': csrf,
              'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ revision: this.attemptRevision }),
          },
        );

        let body = null;

        try {
          body = await response.json();
        } catch {
          body = null;
        }

        if (response.status === 409) {
          this.conflicted = true;
          this.syncStatus = 'conflict';
          this.announce(this.strings.conflict);

          return;
        }

        if (! response.ok) {
          this.announce(body?.message ?? this.gateMessage);

          return;
        }

        this.attemptRevision = body.revision;
        this.attempt = {
          ...this.attempt,
          revision: body.revision,
          current_page_id: body.current_page_id,
          status: body.status,
        };

        if (body.status === 'completed') {
          this.readOnly = true;
          this.announce('Lesson complete.');

          return;
        }

        const nextIndex = this.pages.findIndex((page) => page.page_id === body.current_page_id);

        if (nextIndex >= 0) {
          this.goTo(nextIndex);
        }
      } catch {
        this.announce(this.strings.pending);
      }
    },

    goBack() {
      if (! this.canGoBack) {
        return;
      }

      this.goTo(this.currentIndex - 1);
    },

    /** Skipping moves on without marking anything as satisfied. */
    skip() {
      if (! this.allowSkip || this.isLastPage) {
        return;
      }

      this.goTo(this.currentIndex + 1);
    },

    goTo(index) {
      const target = Math.min(Math.max(index, 0), Math.max(this.totalPages - 1, 0));

      if (target === this.currentIndex) {
        return;
      }

      // Speech never follows a student onto another page.
      this.stopReading();
      this.settingsOpen = false;
      this.currentIndex = target;

      this.$nextTick(() => {
        this.focusPageHeading();
        this.scrollToTop();
        this.announcePage();
      });
    },

    focusPageHeading() {
      const heading = this.$root.querySelector(
        `[data-page-index="${this.currentIndex}"] [data-page-heading]`,
      );

      // The heading is focusable programmatically only, and focusing it must
      // not fight the scroll reset that follows.
      heading?.focus({ preventScroll: true });
    },

    scrollToTop() {
      this.$refs.main?.scrollTo?.({ top: 0, behavior: 'auto' });
      window.scrollTo({ top: 0, behavior: 'auto' });
    },

    announcePage() {
      const title = this.currentPage?.title;

      this.announce(
        `Page ${this.currentIndex + 1} of ${this.totalPages}${title ? `: ${title}` : ''}`,
      );
    },

    announceSync(status) {
      const message = this.strings[status];

      if (message) {
        this.announce(message);
      }
    },

    /** Clearing first guarantees the live region sees a change to report. */
    announce(message) {
      if (! message) {
        return;
      }

      this.announcement = '';

      this.$nextTick(() => {
        this.announcement = message;
      });
    },

    isReading(blockId) {
      return this.speech.speaking && this.speech.activeBlockId === blockId;
    },

    toggleReadAloud(blockId) {
      if (this.isReading(blockId)) {
        this.stopReading();

        return;
      }

      this.speechController?.speak(blockId, this.speechSegmentsFor(blockId));
    },

    /** Segments come from the manifest, already redacted by the server. */
    speechSegmentsFor(blockId) {
      for (const page of this.pages) {
        const block = (page.blocks ?? []).find((candidate) => candidate.block_id === blockId);

        if (block) {
          return block.speech ?? [];
        }
      }

      return [];
    },

    pauseReading() {
      this.speechController?.pause();
    },

    resumeReading() {
      this.speechController?.resume();
    },

    stopReading() {
      this.speechController?.stop();
    },

    applyRate() {
      this.speechController?.setRate(this.speech.rate);
    },

    applyVoice() {
      this.speechController?.setVoice(this.speech.voiceUri);
    },

    /**
     * Highlights the segment being spoken where it already sits on the page.
     * Nothing is re-rendered, no text node is split, and the class is paired
     * with aria-current so the state is not carried by colour alone.
     */
    highlightSegment(blockId, segmentId) {
      this.$root.querySelectorAll('[data-speech-id].is-speaking').forEach((element) => {
        element.classList.remove('is-speaking');
        element.removeAttribute('aria-current');
      });

      if (! blockId || ! segmentId) {
        return;
      }

      // A renderer may draw a wide and a narrow form of the same segment.
      // Both are marked; only the visible one reaches the accessibility tree.
      const selector = `[data-block-id=${quoteAttribute(blockId)}] [data-speech-id=${quoteAttribute(segmentId)}]`;

      this.$root.querySelectorAll(selector).forEach((element) => {
        element.classList.add('is-speaking');
        element.setAttribute('aria-current', 'true');
      });
    },
  };
}

/** Segment ids come from author content, so they are quoted, not trusted. */
function quoteAttribute(value) {
  return `"${String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"')}"`;
}
