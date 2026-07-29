<?php

namespace Database\Seeders;

use App\Enums\PageCompletionType;
use App\Models\Lesson;
use App\Models\LessonPage;
use App\Models\User;
use App\Services\LessonPublisher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Builds WEL-6.1.1 "What Is Welding?" as live AUTHORING ROWS (pages and
 * blocks), then publishes it through LessonPublisher so a compiled
 * manifest version exists. Content is placeholder; real content comes
 * later.
 */
class WeldingLessonSeeder extends Seeder
{
    public function run(): void
    {
        $author = User::query()->firstOrCreate(
            ['email' => 'author@teched.test'],
            [
                'name' => 'Seed Author',
                'password' => bcrypt(Str::random(32)),
            ]
        );

        // Re-seeding replaces the lesson wholesale (DB-level cascade removes
        // pages, blocks, and versions without firing model events).
        Lesson::withTrashed()->where('code', 'WEL-6.1.1')->forceDelete();

        $lesson = Lesson::query()->create([
            'code' => 'WEL-6.1.1',
            'title' => 'What Is Welding?',
            'description' => 'An introduction to welding: what it is, where it shows up, and how engineers think about joining materials.',
            'subject' => 'Welding',
            'grade_range' => '6-8',
            'estimated_minutes' => 45,
            'learning_target' => 'I can explain what welding is and why engineers choose it to join materials.',
            'success_criteria' => [
                'I can define welding in my own words.',
                'I can identify the parts of a basic weld.',
                'I can give one example of welding in the real world.',
            ],
            'standards' => ['CTE-WEL-6.1'],
            'created_by' => $author->id,
            'updated_by' => $author->id,
        ]);

        $this->pageWelcome($lesson);
        $this->pageThinkLikeAnEngineer($lesson);
        $this->pageVideo($lesson);
        $this->pageReading($lesson);
        $this->pageVocabularyMatch($lesson);
        $this->pageWeldingDiagram($lesson);
        $this->pageEngineeringChallenge($lesson);
        $this->pageQuiz($lesson);
        $this->pageResults($lesson);

        app(LessonPublisher::class)->publish($lesson, $author);
    }

    private function makePage(Lesson $lesson, int $position, string $title, PageCompletionType $type, array $settings = []): LessonPage
    {
        return $lesson->pages()->create([
            'title' => $title,
            'position' => $position,
            'completion_type' => $type,
            'estimated_minutes' => 5,
            'settings' => $settings,
        ]);
    }

    private function fullGrading(string $rule, ?int $minScore = null, ?int $points = null): array
    {
        return [
            'rule' => $rule,
            'min_score' => $minScore,
            'allow_retry' => true,
            'max_attempts' => null,
            'show_feedback' => true,
            'record_first_attempt' => true,
            'points' => $points,
        ];
    }

    private function pageWelcome(Lesson $lesson): void
    {
        $page = $this->makePage($lesson, 1, 'Welcome', PageCompletionType::View);

        $page->blocks()->create([
            'type' => 'rich_text',
            'position' => 1,
            'config' => [
                'html' => '<h2>Welcome to What Is Welding?</h2><p>In this lesson you will discover what welding is, where it shows up in everyday life, and how engineers use it to build the world around you.</p>',
            ],
        ]);

        $page->blocks()->create([
            'type' => 'callout',
            'position' => 2,
            'config' => [
                'style' => 'info',
                'heading' => 'How this lesson works',
                'html' => '<p>Work through each page at your own pace. Some pages have activities to complete before you can move on.</p>',
            ],
        ]);
    }

    private function pageThinkLikeAnEngineer(Lesson $lesson): void
    {
        $page = $this->makePage($lesson, 2, 'Think Like an Engineer', PageCompletionType::View);

        $page->blocks()->create([
            'type' => 'rich_text',
            'position' => 1,
            'config' => [
                'html' => '<h2>Think Like an Engineer</h2><p>Look around the room. How many things can you find that are made of two or more pieces of metal joined together? Engineers must decide <em>how</em> to join materials: bolts, glue, rivets, or welds.</p>',
            ],
        ]);

        $page->blocks()->create([
            'type' => 'callout',
            'position' => 2,
            'config' => [
                'style' => 'tip',
                'heading' => 'Keep this question in mind',
                'html' => '<p>Why might a permanent joint be better than a removable one — and when might it be worse?</p>',
            ],
        ]);
    }

    private function pageVideo(Lesson $lesson): void
    {
        $page = $this->makePage($lesson, 3, 'Video: Welding in Action', PageCompletionType::ConfirmVideo);

        $page->blocks()->create([
            'type' => 'video',
            'position' => 1,
            'config' => [
                'provider' => 'youtube',
                'video_id' => 'PLACEHOLDER01',
                'title' => 'Welding in Action',
                'instructions' => 'Watch the full video, then confirm you watched it.',
                'focus_questions' => [
                    ['id' => (string) Str::ulid(), 'text' => 'What happens to the metal at the weld joint?'],
                    ['id' => (string) Str::ulid(), 'text' => 'What safety equipment does the welder wear?'],
                ],
                'require_confirmation' => true,
                'captions_available' => true,
                'transcript_html' => '<p>Placeholder transcript: welding fuses metal parts by melting them at the joint…</p>',
            ],
        ]);

        $page->blocks()->create([
            'type' => 'callout',
            'position' => 2,
            'config' => [
                'style' => 'warning',
                'heading' => 'Safety note',
                'html' => '<p>Never look directly at a welding arc without proper eye protection.</p>',
            ],
        ]);
    }

    private function pageReading(Lesson $lesson): void
    {
        $page = $this->makePage($lesson, 4, 'Reading: What Is Welding?', PageCompletionType::View);

        $page->blocks()->create([
            'type' => 'rich_text',
            'position' => 1,
            'config' => [
                'html' => '<h2>What Is Welding?</h2><p>Welding is a way of permanently joining materials — usually metals — by melting them at the joint so they fuse into one piece. Unlike bolting or gluing, a good weld makes the two parts behave like a single piece of metal.</p><p>Welders use heat from electricity or burning gas. The melted zone is called the <strong>weld pool</strong>, and extra metal called <strong>filler</strong> is often added for strength.</p>',
            ],
        ]);

        $page->blocks()->create([
            'type' => 'vocabulary_cards',
            'position' => 2,
            'config' => [
                'terms' => [
                    [
                        'id' => (string) Str::ulid(),
                        'term' => 'Weld',
                        'definition' => 'A permanent joint made by melting and fusing materials together.',
                        'analogy' => 'Like melting two crayons so they cool into one crayon.',
                    ],
                    [
                        'id' => (string) Str::ulid(),
                        'term' => 'Weld pool',
                        'definition' => 'The small puddle of melted metal that forms at the joint while welding.',
                        'analogy' => null,
                    ],
                    [
                        'id' => (string) Str::ulid(),
                        'term' => 'Filler',
                        'definition' => 'Extra metal added into the joint to make the weld stronger.',
                        'analogy' => 'Like adding extra glue into a gap before pressing pieces together.',
                    ],
                ],
                'reveal_mode' => 'tap',
            ],
        ]);
    }

    private function pageVocabularyMatch(Lesson $lesson): void
    {
        $page = $this->makePage(
            $lesson,
            5,
            'Vocabulary Match',
            PageCompletionType::CompleteActivity,
            ['require_all_blocks' => true]
        );

        $page->blocks()->create([
            'type' => 'matching',
            'position' => 1,
            'config' => [
                'instructions' => 'Match each welding term to its description.',
                'pairs' => [
                    ['id' => (string) Str::ulid(), 'label' => 'Weld', 'description' => 'A permanent joint made by fusing materials'],
                    ['id' => (string) Str::ulid(), 'label' => 'Weld pool', 'description' => 'The puddle of melted metal at the joint'],
                    ['id' => (string) Str::ulid(), 'label' => 'Filler', 'description' => 'Extra metal added for strength'],
                ],
                'shuffle' => true,
            ],
            'grading' => $this->fullGrading('completion_only'),
        ]);
    }

    private function pageWeldingDiagram(Lesson $lesson): void
    {
        $page = $this->makePage($lesson, 6, 'Welding Diagram', PageCompletionType::CompleteActivity);

        $bankTorch = (string) Str::ulid();
        $bankPool = (string) Str::ulid();
        $bankBase = (string) Str::ulid();

        $page->blocks()->create([
            'type' => 'image_labeling',
            'position' => 1,
            'config' => [
                'image_url' => 'https://example.com/placeholder/welding-diagram.png',
                'image_alt' => 'Cross-section diagram of a weld in progress',
                'long_description' => 'The diagram shows a torch above a joint between two base metal plates, with a melted weld pool between them.',
                'instructions' => 'Drag each label onto the matching numbered point on the diagram.',
                'hotspots' => [
                    [
                        'id' => (string) Str::ulid(),
                        'number' => 1,
                        'x_pct' => 50.0,
                        'y_pct' => 15.0,
                        'answer_id' => $bankTorch,
                        'description' => 'The tool at the top producing heat',
                    ],
                    [
                        'id' => (string) Str::ulid(),
                        'number' => 2,
                        'x_pct' => 50.0,
                        'y_pct' => 55.0,
                        'answer_id' => $bankPool,
                        'description' => 'The melted area at the joint',
                    ],
                    [
                        'id' => (string) Str::ulid(),
                        'number' => 3,
                        'x_pct' => 20.0,
                        'y_pct' => 75.0,
                        'answer_id' => $bankBase,
                        'description' => 'One of the plates being joined',
                    ],
                ],
                'bank' => [
                    ['id' => $bankTorch, 'label' => 'Torch'],
                    ['id' => $bankPool, 'label' => 'Weld pool'],
                    ['id' => $bankBase, 'label' => 'Base metal'],
                ],
            ],
            'grading' => $this->fullGrading('all_correct'),
        ]);
    }

    private function pageEngineeringChallenge(Lesson $lesson): void
    {
        $page = $this->makePage($lesson, 7, 'Engineering Challenge', PageCompletionType::SubmitRequired);

        $page->blocks()->create([
            'type' => 'static_table',
            'position' => 1,
            'config' => [
                'caption' => 'Ways to join two metal parts',
                'headers' => ['Method', 'Permanent?', 'Strength'],
                'rows' => [
                    ['Bolts', 'No', 'Medium'],
                    ['Glue/adhesive', 'Mostly', 'Low to medium'],
                    ['Weld', 'Yes', 'High'],
                ],
            ],
        ]);

        $page->blocks()->create([
            'type' => 'callout',
            'position' => 2,
            'config' => [
                'style' => 'info',
                'heading' => 'Your challenge',
                'html' => '<p>A bike frame has cracked at a joint. The repair shop must choose how to fix it. Use the table above to make your case.</p>',
            ],
        ]);

        $page->blocks()->create([
            'type' => 'cer',
            'position' => 3,
            'config' => [
                'scenario_html' => '<p>A bike frame cracked at a welded joint. The shop can bolt a metal brace over the crack or re-weld the joint. Which repair should they choose?</p>',
                'fields' => [
                    [
                        'id' => (string) Str::ulid(),
                        'label' => 'Claim',
                        'placeholder' => 'State which repair the shop should choose.',
                        'min_length' => 20,
                    ],
                    [
                        'id' => (string) Str::ulid(),
                        'label' => 'Evidence',
                        'placeholder' => 'Use facts from the table and reading.',
                        'min_length' => 30,
                    ],
                    [
                        'id' => (string) Str::ulid(),
                        'label' => 'Reasoning',
                        'placeholder' => 'Explain why your evidence supports your claim.',
                        'min_length' => 30,
                    ],
                ],
            ],
            'grading' => null,
        ]);
    }

    private function pageQuiz(Lesson $lesson): void
    {
        $page = $this->makePage(
            $lesson,
            8,
            'Quiz',
            PageCompletionType::PassActivity,
            ['minimum_score' => 80, 'allow_back_navigation' => false]
        );

        $q1Correct = (string) Str::ulid();
        $q1Wrong = (string) Str::ulid();
        $q2Correct = (string) Str::ulid();
        $q2Wrong = (string) Str::ulid();
        $q3Correct = (string) Str::ulid();
        $q3Wrong = (string) Str::ulid();

        $page->blocks()->create([
            'type' => 'quiz',
            'position' => 1,
            'config' => [
                'questions' => [
                    [
                        'id' => (string) Str::ulid(),
                        'prompt' => 'What makes welding different from bolting?',
                        'options' => [
                            ['id' => $q1Correct, 'text' => 'It permanently fuses the parts into one piece'],
                            ['id' => $q1Wrong, 'text' => 'It can be taken apart easily later'],
                        ],
                        'answer_id' => $q1Correct,
                        'feedback' => 'Welding melts the parts at the joint so they fuse permanently.',
                        'source_ref' => ['page' => 'Reading: What Is Welding?', 'excerpt' => 'Welding is a way of permanently joining materials.'],
                    ],
                    [
                        'id' => (string) Str::ulid(),
                        'prompt' => 'What is the weld pool?',
                        'options' => [
                            ['id' => $q2Correct, 'text' => 'The puddle of melted metal at the joint'],
                            ['id' => $q2Wrong, 'text' => 'The bucket of water used to cool tools'],
                        ],
                        'answer_id' => $q2Correct,
                        'feedback' => 'The weld pool is the melted zone that fuses the parts.',
                        'source_ref' => null,
                    ],
                    [
                        'id' => (string) Str::ulid(),
                        'prompt' => 'Why is filler metal sometimes added to a weld?',
                        'options' => [
                            ['id' => $q3Correct, 'text' => 'To make the joint stronger'],
                            ['id' => $q3Wrong, 'text' => 'To make the joint easier to unscrew'],
                        ],
                        'answer_id' => $q3Correct,
                        'feedback' => 'Filler adds material and strength to the joint.',
                        'source_ref' => null,
                    ],
                ],
                'shuffle_questions' => false,
            ],
            'grading' => $this->fullGrading('min_score', minScore: 80, points: 10),
        ]);
    }

    private function pageResults(Lesson $lesson): void
    {
        $page = $this->makePage($lesson, 9, 'Results', PageCompletionType::View);

        // Introductory copy only: calculated scores and student responses
        // are rendered as platform UI from the attempt record, never as
        // authored blocks.
        $page->blocks()->create([
            'type' => 'rich_text',
            'position' => 1,
            'config' => [
                'html' => '<h2>Nice work!</h2><p>You have reached the end of <strong>What Is Welding?</strong>. Your scores and responses are shown below.</p>',
            ],
        ]);
    }
}
