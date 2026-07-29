import { createCompletionRegistry } from './completion';
import { createSpeechController, RATE } from './speech';

/**
 * The student player, as an Alpine component.
 *
 * The whole manifest is handed over by the server in one go, so navigation
 * is a change of index rather than a request. State lives in memory only:
 * nothing about a student's place, answers, or progress is written anywhere.
 * The single exception is read-aloud preferences, which speech.js keeps
 * under its own namespaced keys.
 */
export function lessonPlayer(manifest) {
  return {
    manifest,
    pages: Array.isArray(manifest?.pages) ? manifest.pages : [],
    currentIndex: 0,
    announcement: '',
    settingsOpen: false,

    /**
     * Bumped whenever the set of contributors changes, so the Continue gate
     * recomputes when a renderer registers or drops one. Changes to whether
     * a contributor is satisfied need no counter: reading its state inside
     * the gate is what makes the gate depend on it.
     */
    registryVersion: 0,

    completion: createCompletionRegistry(),
    speechController: null,

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
    },

    destroy() {
      this.speechController?.destroy();
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
      return this.pageSettings.allow_skip === true;
    },

    get completionState() {
      // Depend on the contributor set as well as on their answers.
      void this.registryVersion;

      const page = this.currentPage;

      if (page === null) {
        return { satisfied: true, message: null };
      }

      return this.completion.evaluate(page.page_id, page.completion_type, { shown: true });
    },

    get canContinue() {
      return this.completionState.satisfied;
    },

    /** Why Continue is unavailable, or '' when it is available. */
    get gateMessage() {
      const state = this.completionState;

      return state.satisfied ? '' : (state.message ?? '');
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

    goForward() {
      if (this.isLastPage) {
        return;
      }

      if (!this.canContinue) {
        this.announce(this.gateMessage);

        return;
      }

      this.goTo(this.currentIndex + 1);
    },

    goBack() {
      if (!this.canGoBack) {
        return;
      }

      this.goTo(this.currentIndex - 1);
    },

    /** Skipping moves on without marking anything as satisfied. */
    skip() {
      if (!this.allowSkip || this.isLastPage) {
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

    /** Clearing first guarantees the live region sees a change to report. */
    announce(message) {
      if (!message) {
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

      if (!blockId || !segmentId) {
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
