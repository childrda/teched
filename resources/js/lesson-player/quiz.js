import { seededShuffle } from './seeded-shuffle';

/**
 * Alpine component for a quiz block: local answers, a grading POST, and a
 * gradable completion contributor.
 *
 * Attempt state changes only on a successful HTTP 200 whose body is a grading
 * envelope ({ result, attempts }) with a six-key public result. Everything
 * else leaves that state untouched and is surfaced as an error.
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
    attemptsInfo: null,
    submitting: false,
    error: '',
    announcement: '',
    blockedAnnounced: false,
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

      if (submission && isGradingEnvelope(submission.latest_result)) {
        this.applyEnvelope(submission.latest_result, {
          first: submission.first_result,
        });
      }

      if (this.isBlocked) {
        this.announceBlockedOnce();
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
          this.isBlocked
            ? (this.strings.no_attempts_remaining ?? this.strings.gate_pass ?? this.strings.gate ?? '')
            : this.completionType === 'pass_activity'
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
      if (this.readOnly || this.isBlocked) {
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

    get isBlocked() {
      if (this.readOnly || this.isPassed) {
        return false;
      }

      const info = this.attemptsInfo;

      return (
        info != null &&
        info.allowed != null &&
        typeof info.remaining === 'number' &&
        info.remaining <= 0
      );
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

    get attemptsSummary() {
      const info = this.attemptsInfo;

      if (! info || info.allowed == null) {
        return '';
      }

      return fill(this.strings.attempts_remaining, {
        used: info.used,
        allowed: info.allowed,
        remaining: info.remaining,
      });
    },

    firstUnansweredId() {
      return questionIds.find((id) => this.answers[id] == null || this.answers[id] === '') ?? null;
    },

    applyEnvelope(envelope, { first = undefined } = {}) {
      this.latestResult = envelope.result;
      this.attemptsInfo = envelope.attempts;
      this.attemptCount = envelope.attempts?.used ?? this.attemptCount;

      if (first === undefined) {
        if (this.firstResult === null) {
          this.firstResult = envelope.result;
        }
      } else if (first && isGradingEnvelope(first)) {
        this.firstResult = first.result;
      } else {
        this.firstResult = null;
      }
    },

    announceBlockedOnce() {
      if (this.blockedAnnounced) {
        return;
      }

      this.blockedAnnounced = true;
      this.announce(this.strings.no_attempts_remaining ?? '');
    },

    async submit() {
      if (this.submitting || this.readOnly) {
        return;
      }

      if (this.isBlocked) {
        this.error = this.strings.submit_unavailable ?? this.strings.no_attempts_remaining ?? '';
        this.announceBlockedOnce();

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

        if (response.status === 422 && body?.message) {
          this.error = body.message;
          this.announce(this.error);

          if (typeof body.message === 'string' && body.message.includes('No attempts remain')) {
            this.attemptsInfo = {
              used: this.attemptsInfo?.used ?? this.attemptCount,
              allowed: this.attemptsInfo?.allowed ?? this.attemptCount,
              remaining: 0,
            };
            this.announceBlockedOnce();
          }

          return;
        }

        if (! response.ok || ! isGradingEnvelope(body)) {
          this.error = this.strings.error ?? '';
          this.announce(this.error);

          return;
        }

        this.applyEnvelope(body);
        this.error = '';

        const passLabel = body.result.passed ? this.strings.passed : this.strings.failed;

        this.announce(`${this.resultSummary}. ${passLabel}`);

        if (this.isBlocked) {
          this.announceBlockedOnce();
        }
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

/** Exactly `result` and `attempts`, with a valid six-key public result. */
export function isGradingEnvelope(body) {
  if (! body || typeof body !== 'object' || Array.isArray(body)) {
    return false;
  }

  const keys = Object.keys(body).sort();

  if (keys.length !== 2 || keys[0] !== 'attempts' || keys[1] !== 'result') {
    return false;
  }

  if (! isAttemptsInfo(body.attempts)) {
    return false;
  }

  return isPublicResult(body.result);
}

/**
 * The six keys the grading result object carries — nothing else counts.
 * Nested `reveal` is validated separately when non-null so a malformed
 * reveal rejects without accepting a five-key body.
 */
export function isPublicResult(body) {
  if (! body || typeof body !== 'object' || Array.isArray(body)) {
    return false;
  }

  const keys = Object.keys(body).sort();
  const expected = [
    'max_score',
    'passed',
    'percentage',
    'requires_manual_review',
    'reveal',
    'score',
  ];

  if (keys.length !== expected.length || keys.some((key, index) => key !== expected[index])) {
    return false;
  }

  if (
    typeof body.score !== 'number' ||
    typeof body.max_score !== 'number' ||
    typeof body.percentage !== 'number' ||
    typeof body.passed !== 'boolean' ||
    typeof body.requires_manual_review !== 'boolean'
  ) {
    return false;
  }

  if (body.reveal === null) {
    return true;
  }

  return isRevealObject(body.reveal);
}

function isRevealObject(reveal) {
  if (! reveal || typeof reveal !== 'object' || Array.isArray(reveal)) {
    return false;
  }

  const keys = Object.keys(reveal).sort();

  if (keys.length !== 2 || keys[0] !== 'items' || keys[1] !== 'trigger') {
    return false;
  }

  if (reveal.trigger !== 'passed' && reveal.trigger !== 'final_attempt') {
    return false;
  }

  if (! Array.isArray(reveal.items)) {
    return false;
  }

  return reveal.items.every((item) => {
    if (! item || typeof item !== 'object' || Array.isArray(item)) {
      return false;
    }

    const itemKeys = Object.keys(item).sort();
    const expected = ['correct', 'correct_option_id', 'feedback', 'question_id'];

    if (itemKeys.length !== expected.length || itemKeys.some((key, index) => key !== expected[index])) {
      return false;
    }

    return (
      typeof item.question_id === 'string' &&
      typeof item.correct === 'boolean' &&
      (item.feedback === null || typeof item.feedback === 'string') &&
      (item.correct_option_id === null || typeof item.correct_option_id === 'string')
    );
  });
}

function isAttemptsInfo(info) {
  if (! info || typeof info !== 'object' || Array.isArray(info)) {
    return false;
  }

  const keys = Object.keys(info).sort();

  if (keys.length !== 3 || keys[0] !== 'allowed' || keys[1] !== 'remaining' || keys[2] !== 'used') {
    return false;
  }

  if (typeof info.used !== 'number') {
    return false;
  }

  const unlimited = info.allowed === null && info.remaining === null;
  const limited =
    typeof info.allowed === 'number' && typeof info.remaining === 'number';

  return unlimited || limited;
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
