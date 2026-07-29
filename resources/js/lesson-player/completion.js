/**
 * Page completion: what a page still needs before a student may continue.
 *
 * Renderers register contributors; this module decides whether the page's
 * completion rule is satisfied. It holds no DOM and no framework, so the
 * rules can be unit tested on their own and so Phase 2B's activity blocks
 * register exactly the way a content block does today.
 *
 * A contributor is:
 *   {
 *     id: string,          // derived from a block_id, never a DOM index
 *     category: string,    // one of CONTRIBUTOR_CATEGORIES
 *     isSatisfied(): boolean,  // the student has finished this
 *     isPassed?(): boolean,    // ...and met the bar; see below
 *     message: string,     // shown when this is what is holding the page
 *   }
 *
 * Finishing and passing are separate questions. isSatisfied() answers "has
 * the student done this?"; isPassed() answers "did they meet the bar?" A
 * submitted quiz scoring 40% is satisfied but not passed, and a page may
 * legitimately require either one. A gradable contributor must therefore
 * implement BOTH predicates, and registration rejects one that does not:
 * falling back to isSatisfied() under pass_activity would let a
 * completed-but-failed activity open the gate.
 *
 * Content blocks register nothing by default: a page of prose, images, and
 * tables is complete as soon as it is shown. A video registers a single
 * confirmation contributor, and only when its author required confirmation.
 */

export const CONTRIBUTOR_CATEGORIES = Object.freeze([
  'confirmation',
  'response',
  'activity',
  'gradable',
]);

/**
 * The contributor categories each page completion rule considers.
 *
 * A rule with no relevant contributors is satisfied, which is why `view`
 * needs no categories and why an unknown rule cannot trap a student.
 *
 * `pass_activity` weighs gradable contributors only, and is the one rule that
 * asks them isPassed() rather than isSatisfied(). What passing means for a
 * given activity belongs to the activity blocks in Phase 2B.
 */
export const RULE_CATEGORIES = Object.freeze({
  view: [],
  confirm_video: ['confirmation'],
  submit_required: ['response', 'activity', 'gradable'],
  complete_activity: ['activity', 'gradable'],
  pass_activity: ['gradable'],
});

/**
 * Every relevant contributor must be satisfied. A page's
 * settings.require_all_blocks is carried through the manifest untouched but
 * is deliberately not consulted here: partial completion and optional
 * activities are Phase 2B's to define, not this phase's to guess.
 */
const DEFAULT_BLOCKED_MESSAGE = 'Finish this page to continue.';

const NOT_YET_SHOWN_MESSAGE = 'Open this page to continue.';

export function createCompletionRegistry() {
  /**
   * Insertion order is DOM order, so the first unsatisfied contributor is
   * also the first one a student would meet reading down the page.
   *
   * @type {Map<string, Array<object>>}
   */
  const pages = new Map();

  function bucket(pageId) {
    const key = String(pageId);

    if (!pages.has(key)) {
      pages.set(key, []);
    }

    return pages.get(key);
  }

  function assertValid(contributor) {
    if (!contributor || typeof contributor !== 'object') {
      throw new TypeError('A completion contributor must be an object.');
    }

    if (typeof contributor.id !== 'string' || contributor.id === '') {
      throw new TypeError('A completion contributor needs a non-empty string id.');
    }

    if (!CONTRIBUTOR_CATEGORIES.includes(contributor.category)) {
      throw new TypeError(
        `Unknown completion category "${contributor.category}" for contributor "${contributor.id}".`,
      );
    }

    if (typeof contributor.isSatisfied !== 'function') {
      throw new TypeError(`Contributor "${contributor.id}" needs an isSatisfied() function.`);
    }

    if (contributor.isPassed !== undefined && typeof contributor.isPassed !== 'function') {
      throw new TypeError(`Contributor "${contributor.id}" has an isPassed that is not a function.`);
    }

    // Mandatory, not merely documented: pass_activity calls isPassed()
    // directly, and a missing one must fail here rather than at the gate.
    if (contributor.category === 'gradable' && typeof contributor.isPassed !== 'function') {
      throw new TypeError(
        `Gradable contributor "${contributor.id}" needs an isPassed() function; see the contract comment at the top of this file.`,
      );
    }
  }

  function register(pageId, contributor) {
    assertValid(contributor);

    const contributors = bucket(pageId);
    const existing = contributors.findIndex((candidate) => candidate.id === contributor.id);

    // Re-registering the same id replaces it in place, so a re-rendered
    // block keeps its position in the page rather than jumping to the end.
    if (existing === -1) {
      contributors.push(contributor);
    } else {
      contributors[existing] = contributor;
    }

    return () => unregister(pageId, contributor.id);
  }

  function unregister(pageId, id) {
    const contributors = bucket(pageId);
    const index = contributors.findIndex((candidate) => candidate.id === id);

    if (index !== -1) {
      contributors.splice(index, 1);
    }
  }

  function contributors(pageId) {
    return [...bucket(pageId)];
  }

  function relevantContributors(pageId, rule) {
    const categories = RULE_CATEGORIES[rule] ?? [];

    return bucket(pageId).filter((contributor) => categories.includes(contributor.category));
  }

  /**
   * Whether a contributor clears the bar this rule sets. Only pass_activity
   * asks a gradable contributor to have been passed; every other rule asks
   * whether it was finished, so a page that merely requires an attempt is
   * never blocked by a low score.
   */
  function meetsRule(contributor, rule) {
    if (rule === 'pass_activity' && contributor.category === 'gradable') {
      // Registration guarantees a gradable contributor has isPassed().
      return contributor.isPassed() === true;
    }

    return contributor.isSatisfied() === true;
  }

  /**
   * @param {object} options
   * @param {boolean} options.shown whether the student has been shown the page
   * @returns {{ satisfied: boolean, message: string|null }}
   */
  function evaluate(pageId, rule, { shown = false } = {}) {
    if (rule === 'view') {
      return shown === true
        ? { satisfied: true, message: null }
        : { satisfied: false, message: NOT_YET_SHOWN_MESSAGE };
    }

    const blocking = relevantContributors(pageId, rule).find(
      (contributor) => !meetsRule(contributor, rule),
    );

    if (blocking === undefined) {
      return { satisfied: true, message: null };
    }

    return {
      satisfied: false,
      message: blocking.message || DEFAULT_BLOCKED_MESSAGE,
    };
  }

  return { register, unregister, contributors, relevantContributors, evaluate };
}
