import { seededShuffle } from './seeded-shuffle';

/**
 * Alpine component for a quiz block: local answers, a grading POST, and a
 * gradable completion contributor.
 *
 * Attempt state ({ attemptCount, firstResult, latestResult }) changes only
 * on a successful HTTP 200 whose body is the expected five-key result.
 * Everything else — 422, 404, network failure, malformed body — leaves those
 * three untouched and is surfaced as an error.
 *
 * Question shuffle is normally done in Blade (PHP SeededShuffle). The Alpine
 * path below only runs when config.shuffle is true — kept for parity with
 * the shared algorithm, not as the primary path.
 *
 * @param {{
 *   blockId: string,
 *   pageId: string,
 *   completionType: string,
 *   shuffle: boolean,
 *   questions: {id: string, prompt: string, options: {id: string, text: string}[]}[],
 *   strings: Record<string, string>,
 * }} config
 */
export function quizActivity(config = {}) {
  const questionIds = (config.questions ?? []).map((question) => question.id);

  return {
    blockId: config.blockId ?? '',
    pageId: config.pageId ?? '',
    completionType: config.completionType ?? '',
    strings: config.strings ?? {},
    questions: [...(config.questions ?? [])],
    answers: Object.fromEntries(questionIds.map((id) => [id, null])),
    attemptCount: 0,
    firstResult: null,
    latestResult: null,
    submitting: false,
    error: '',
    announcement: '',
    readOnly: false,
    disposeContributor: null,

    init() {
      const player = this.player();
      this.readOnly = player?.readOnly === true;

      if (config.shuffle === true) {
        this.questions = seededShuffle(
          [...(config.questions ?? [])],
          player?.shuffleSeed?.() ?? '',
          this.blockId,
        );
      }

      this.restoreState(player?.stateFor?.(this.blockId));

      const submission = player?.submissionFor?.(this.blockId);

      if (submission && isPublicResult(submission)) {
        this.latestResult = {
          score: submission.score,
          max_score: submission.max_score,
          percentage: submission.percentage,
          passed: submission.passed,
          requires_manual_review: submission.requires_manual_review,
        };
        this.firstResult = this.latestResult;
        this.attemptCount = submission.attempt_number ?? 1;
      }
    },

    destroy() {
      this.disposeContributor?.();
    },

    player() {
      const root = this.$el?.closest?.('[data-lesson-code]');

      return root && window.Alpine ? window.Alpine.$data(root) : null;
    },

    captureDisposer(dispose) {
      this.disposeContributor = typeof dispose === 'function' ? dispose : null;
    },

    contributor() {
      return {
        id: `${this.blockId}:quiz`,
        category: 'gradable',
        isSatisfied: () => this.isSatisfied,
        isPassed: () => this.isPassed,
        message:
          this.completionType === 'pass_activity'
            ? (this.strings.gate_pass ?? this.strings.gate ?? '')
            : (this.strings.gate ?? ''),
      };
    },

    serializeState() {
      return { answers: { ...this.answers } };
    },

    restoreState(state) {
      if (! state || typeof state !== 'object' || typeof state.answers !== 'object' || ! state.answers) {
        return;
      }

      for (const id of questionIds) {
        const value = state.answers[id];

        if (value === null || typeof value === 'string') {
          this.answers[id] = value;
        }
      }
    },

    onAnswer() {
      if (this.readOnly) {
        return;
      }

      this.player()?.queueSave?.(this.blockId, this.serializeState());
    },

    get isSatisfied() {
      return this.latestResult !== null;
    },

    get isPassed() {
      return this.latestResult?.passed === true;
    },

    get allAnswered() {
      return questionIds.every((id) => this.answers[id] != null && this.answers[id] !== '');
    },

    get resultSummary() {
      const result = this.latestResult;

      if (! result) {
        return '';
      }

      return fill(this.strings.score, {
        score: result.score,
        max: result.max_score,
        percentage: result.percentage,
      });
    },

    firstUnansweredId() {
      return questionIds.find((id) => this.answers[id] == null || this.answers[id] === '') ?? null;
    },

    async submit() {
      if (this.submitting || this.readOnly) {
        return;
      }

      if (! this.allAnswered) {
        this.error = '';
        this.announce(this.strings.answer_every);
        this.focusQuestion(this.firstUnansweredId());

        return;
      }

      this.submitting = true;
      this.error = '';
      this.announce(this.strings.submitting);

      const playerEl = this.$el?.closest?.('[data-lesson-code]') ?? null;
      const lessonCode = playerEl?.dataset?.lessonCode ?? '';
      const player = playerEl && window.Alpine ? window.Alpine.$data(playerEl) : null;
      const versionToken = player?.manifest?.grading_token ?? '';
      const csrf =
        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

      const responsePayload = Object.fromEntries(
        questionIds.map((id) => [id, this.answers[id]]),
      );

      try {
        const response = await fetch(
          `/player/lessons/${encodeURIComponent(lessonCode)}/blocks/${encodeURIComponent(this.blockId)}/grade`,
          {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              Accept: 'application/json',
              'X-CSRF-TOKEN': csrf,
              'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
              version_token: versionToken,
              response: responsePayload,
            }),
          },
        );

        let body = null;

        try {
          body = await response.json();
        } catch {
          body = null;
        }

        if (! response.ok || ! isPublicResult(body)) {
          this.error = this.strings.error ?? '';
          this.announce(this.error);

          return;
        }

        // Display-only counter — the server assigns attempt_number.
        this.attemptCount += 1;

        if (this.firstResult === null) {
          this.firstResult = body;
        }

        this.latestResult = body;
        this.error = '';

        const passLabel = body.passed ? this.strings.passed : this.strings.failed;

        this.announce(`${this.resultSummary}. ${passLabel}`);
      } catch {
        this.error = this.strings.error ?? '';
        this.announce(this.error);
      } finally {
        this.submitting = false;
      }
    },

    focusQuestion(questionId) {
      if (! questionId) {
        return;
      }

      this.$nextTick(() => {
        this.$root
          ?.querySelector?.(`[data-question-id=${quote(questionId)}] input[type="radio"]`)
          ?.focus();
      });
    },

    announce(message) {
      if (! message) {
        return;
      }

      this.announcement = '';

      this.$nextTick(() => {
        this.announcement = message;
      });
    },
  };
}

/** The five keys the grading endpoint returns — nothing else counts as graded. */
export function isPublicResult(body) {
  if (! body || typeof body !== 'object' || Array.isArray(body)) {
    return false;
  }

  const keys = Object.keys(body).filter((key) => key !== 'attempt_number').sort();
  const expected = ['max_score', 'passed', 'percentage', 'requires_manual_review', 'score'];

  if (keys.length !== expected.length || keys.some((key, index) => key !== expected[index])) {
    return false;
  }

  return (
    typeof body.score === 'number' &&
    typeof body.max_score === 'number' &&
    typeof body.percentage === 'number' &&
    typeof body.passed === 'boolean' &&
    typeof body.requires_manual_review === 'boolean'
  );
}

function fill(template, values) {
  return Object.entries(values).reduce(
    (text, [key, value]) => text.replaceAll(`:${key}`, String(value)),
    template ?? '',
  );
}

function quote(value) {
  return `"${String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"')}"`;
}
