<?php

namespace Database\Seeders;

use App\Enums\PageCompletionType;
use App\Enums\UserRole;
use App\Models\Lesson;
use App\Models\LessonPage;
use App\Models\User;
use App\Services\LessonPublisher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Builds WEL-6.1.1 "What Is Welding?" as live AUTHORING ROWS (pages and
 * blocks), then publishes it through LessonPublisher so a compiled
 * manifest version exists.
 *
 * CONTENT SOURCE: GO TEC WEL 6.1.1 "Introduction to Welding" teacher
 * guide, student worksheets, and slide deck (Institute for Advanced
 * Learning and Research, (c)2026). Definitions, analogies, the comparison
 * chart, the CER scenario, and the diagram labels are taken from those
 * documents. Quiz items were written from that material and carry
 * source_ref pointers back to it.
 *
 * Nested stable IDs are readable slugs rather than ULIDs so the manifest
 * is reproducible across re-seeds and reviewable in source control.
 * page_id and block_id are still auto-generated ULIDs by the models.
 */
class WeldingLessonSeeder extends Seeder
{
    /**
     * Diagram asset, committed under public/ rather than storage/. Seed
     * fixtures have to survive a fresh clone, and storage/app/public is both
     * gitignored and dependent on a storage:link symlink existing.
     */
    private const DIAGRAM_URL = '/lessons/wel-6-1-1/welding-diagram.png';

    public function run(): void
    {
        // Prefer the seeded teacher (UserSeeder). When this seeder runs alone
        // in a test, create that same account rather than an orphan author.
        $author = User::query()->firstOrCreate(
            ['email' => UserSeeder::TEACHER_EMAIL],
            [
                'name' => 'Seed Teacher',
                'password' => Hash::make(UserSeeder::DEV_PASSWORD),
            ]
        );

        $author->forceFill(['role' => UserRole::Teacher])->save();

        // Re-seeding replaces the lesson wholesale (DB-level cascade removes
        // pages, blocks, and versions without firing model events).
        Lesson::withTrashed()->where('code', 'WEL-6.1.1')->forceDelete();

        $lesson = Lesson::query()->create([
            'code' => 'WEL-6.1.1',
            'title' => 'What Is Welding?',
            'description' => 'An introduction to welding: what it is, how thermal energy fuses metal, and why engineers choose it for bridges, ships, and buildings.',
            'subject' => 'Welding',
            'grade_range' => '6-12',
            'estimated_minutes' => 55,
            'learning_target' => 'I can explain what welding is, how it joins materials, and why it is important in engineering and manufacturing.',
            'success_criteria' => [
                'I can define welding as a manufacturing process.',
                'I can explain how welding joins materials using thermal energy.',
                'I can identify 3 welding careers and 4 major industries that depend on welding.',
            ],
            'standards' => [
                'GO TEC WEL.1a',
                'GO TEC WEL.1b',
                'GO TEC WEL.1c',
                'GO TEC WEL.1e',
                'CTE Strand 1',
                'CTE Strand 5',
            ],
            'settings' => [
                'default_allow_read_aloud' => true,
            ],
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

    private function makePage(
        Lesson $lesson,
        int $position,
        string $title,
        PageCompletionType $type,
        int $minutes = 5,
        array $settings = []
    ): LessonPage {
        return $lesson->pages()->create([
            'title' => $title,
            'position' => $position,
            'completion_type' => $type,
            'estimated_minutes' => $minutes,
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

    /**
     * The ten GO TEC vocabulary terms with the teacher guide's formal
     * definitions and student-friendly analogies.
     *
     * @return list<array{id: string, term: string, definition: string, analogy: string}>
     */
    private function vocabulary(): array
    {
        return [
            [
                'id' => 'term-welding',
                'term' => 'Welding',
                'definition' => 'A manufacturing process that uses heat, pressure, or both to permanently join materials.',
                'analogy' => 'Melting two chocolate bars together so they re-freeze into one solid block.',
            ],
            [
                'id' => 'term-thermal-energy',
                'term' => 'Thermal Energy',
                'definition' => 'Heat energy that causes solid metals to melt and fuse together.',
                'analogy' => 'The intense heat source that turns ice cubes into liquid water.',
            ],
            [
                'id' => 'term-arc',
                'term' => 'Arc',
                'definition' => 'A continuous high-energy electrical discharge generating temperatures up to 6,500 degrees Fahrenheit.',
                'analogy' => 'A mini controlled bolt of lightning directed right at the seam.',
            ],
            [
                'id' => 'term-fusion',
                'term' => 'Fusion',
                'definition' => 'The process of joining materials permanently by melting them into a single mass.',
                'analogy' => 'Combining separate droplets of wax into one candle.',
            ],
            [
                'id' => 'term-base-metal',
                'term' => 'Base Metal',
                'definition' => 'The original metal pieces being connected by welding.',
                'analogy' => 'The two slices of bread in a grilled cheese sandwich.',
            ],
            [
                'id' => 'term-weld-pool',
                'term' => 'Weld Pool',
                'definition' => 'The localized liquid puddle formed by molten base and filler metals.',
                'analogy' => 'A small liquid lava puddle before it solidifies into rock.',
            ],
            [
                'id' => 'term-filler-material',
                'term' => 'Filler Material',
                'definition' => 'Extra metal added into the weld pool to strengthen and form the joint.',
                'analogy' => 'Extra hot glue squeezed into a crack to make a repair stronger.',
            ],
            [
                'id' => 'term-electrode',
                'term' => 'Electrode',
                'definition' => 'A conductive metal rod or wire that conducts electric current to form the arc.',
                'analogy' => 'The pen tip that delivers electricity instead of ink.',
            ],
            [
                'id' => 'term-joint',
                'term' => 'Joint',
                'definition' => 'The specific junction location where two or more materials are joined.',
                'analogy' => 'The crack between two adjacent floor tiles.',
            ],
            [
                'id' => 'term-weld-bead',
                'term' => 'Weld Bead',
                'definition' => 'The solid protective metal shape formed after the weld pool cools.',
                'analogy' => 'A neat row of overlapping metal coins along the joint line.',
            ],
        ];
    }

    private function pageWelcome(Lesson $lesson): void
    {
        $page = $this->makePage($lesson, 1, 'Welcome', PageCompletionType::View, 3);

        $page->blocks()->create([
            'type' => 'rich_text',
            'position' => 1,
            'config' => [
                'html' => '<h2>What Is Welding?</h2>'
                    .'<p>Welding is one of the most important manufacturing and engineering processes in the world. It is what holds bridges, ships, cars, and buildings together.</p>'
                    .'<p><strong>By the end of this lesson you will be able to:</strong></p>'
                    .'<ul>'
                    .'<li>Define welding as a manufacturing process</li>'
                    .'<li>Explain how welding joins materials using thermal energy</li>'
                    .'<li>Identify 3 welding careers and 4 major industries that depend on welding</li>'
                    .'</ul>',
            ],
        ]);

        $page->blocks()->create([
            'type' => 'callout',
            'position' => 2,
            'config' => [
                'style' => 'info',
                'heading' => 'How this lesson works',
                'html' => '<p>Work through each page at your own pace. Some pages have an activity you must finish before you can continue. Use the read-aloud button on any section if you would like it read to you.</p>',
            ],
        ]);
    }

    private function pageThinkLikeAnEngineer(Lesson $lesson): void
    {
        $page = $this->makePage($lesson, 2, 'Think Like an Engineer', PageCompletionType::View, 5);

        $page->blocks()->create([
            'type' => 'rich_text',
            'position' => 1,
            'config' => [
                'html' => '<h2>What Holds Our World Together?</h2>'
                    .'<p>Think about the bridges you cross, the buildings you enter, and the cars you ride in every day. What keeps these giant structures from falling apart under immense weight and constant vibration?</p>'
                    .'<p>Picture an ocean cargo ship crossing the Atlantic. Waves pound the hull without stopping. Engines vibrate day and night. Saltwater attacks every surface. Right here in Virginia, <strong>Newport News Shipbuilding</strong> and the <strong>Chesapeake Bay Bridge-Tunnel</strong> face those forces constantly.</p>'
                    .'<p>Engineers cannot rely on adhesives that fail in water or bolts that vibrate loose. They rely on <strong>permanent fusion</strong>.</p>',
            ],
        ]);

        $page->blocks()->create([
            'type' => 'callout',
            'position' => 2,
            'config' => [
                'style' => 'tip',
                'heading' => 'Keep these questions in mind',
                'html' => '<ul>'
                    .'<li>Why would glue, nails, or tape fail on an ocean cargo ship or a highway bridge?</li>'
                    .'<li>What could happen to the public if materials in a bridge are joined incorrectly?</li>'
                    .'</ul>',
            ],
        ]);
    }

    private function pageVideo(Lesson $lesson): void
    {
        $page = $this->makePage($lesson, 3, 'Welding in Action', PageCompletionType::ConfirmVideo, 8);

        $page->blocks()->create([
            'type' => 'video',
            'position' => 1,
            'config' => [
                'provider' => 'youtube',
                'video_id' => 'OWThL97tq3k',
                'title' => 'Introduction to Welding',
                'instructions' => 'Watch the full video, then confirm you watched it to continue.',
                'focus_questions' => [
                    ['id' => 'fq-heat-source', 'text' => 'What melts the metal? (Hint: it is hotter than lava.)'],
                    ['id' => 'fq-molten-puddle', 'text' => 'What is the glowing liquid puddle called?'],
                    ['id' => 'fq-cooling', 'text' => 'What happens when the liquid metal cools?'],
                ],
                'require_confirmation' => true,
                'captions_available' => true,
                'transcript_html' => null,
            ],
        ]);

        $page->blocks()->create([
            'type' => 'callout',
            'position' => 2,
            'config' => [
                'style' => 'warning',
                'heading' => 'Safety note',
                'html' => '<p>Never look directly at a welding arc without proper eye protection. The arc produces intense light and ultraviolet radiation that can injure your eyes within seconds.</p>',
            ],
        ]);
    }

    private function pageReading(Lesson $lesson): void
    {
        $page = $this->makePage($lesson, 4, 'How Welding Works', PageCompletionType::View, 10);

        $page->blocks()->create([
            'type' => 'rich_text',
            'position' => 1,
            'config' => [
                'html' => '<h2>Welding vs. Mechanical Fastening</h2>'
                    .'<p>In manufacturing and construction, materials are connected in two main ways. <strong>Mechanical fasteners</strong> such as bolts, screws, rivets, and nails hold two separate components together. The physical parts remain distinct pieces.</p>'
                    .'<p><strong>Welding</strong> is different. It uses extreme thermal energy to melt the edges of the base metals so they flow together and cool into a single, continuous bond. A properly executed weld joint is frequently as strong as, or even stronger than, the original parent metals.</p>'
                    .'<h2>Thermal Energy and Arc Dynamics</h2>'
                    .'<p>An electrical arc generates thermal energy reaching temperatures up to <strong>6,500 degrees Fahrenheit</strong> (about 3,600 degrees Celsius). Structural steel melts at roughly 2,500 degrees Fahrenheit, which means the welding arc provides far more than enough concentrated heat to melt steel instantly on contact. The arc is hotter than many geological magma flows.</p>'
                    .'<h2>Anatomy of a Weld</h2>'
                    .'<ul>'
                    .'<li><strong>Electrode:</strong> a specialized conductor through which electrical current flows to create the arc. In many processes it continuously melts to supply filler material.</li>'
                    .'<li><strong>Base metal:</strong> the primary metal workpieces being permanently joined.</li>'
                    .'<li><strong>Weld pool:</strong> the localized area of liquid metal created by the arc, where fusion occurs.</li>'
                    .'<li><strong>Filler material:</strong> additional alloy added into the weld pool to reinforce the joint, compensate for gaps, and improve structural integrity.</li>'
                    .'<li><strong>Weld bead:</strong> the solidified seam left behind as the electrode moves along the joint. A quality bead shows a uniform pattern resembling stacked coins.</li>'
                    .'</ul>',
            ],
        ]);

        $page->blocks()->create([
            'type' => 'vocabulary_cards',
            'position' => 2,
            'config' => [
                'terms' => $this->vocabulary(),
                'reveal_mode' => 'tap',
            ],
        ]);
    }

    private function pageVocabularyMatch(Lesson $lesson): void
    {
        $page = $this->makePage($lesson, 5, 'Vocabulary Match', PageCompletionType::CompleteActivity, 8);

        $vocabulary = $this->vocabulary();

        // A bank item's id names its own label, which students see anyway.
        // Slot ids are positional, so nothing about a slot hints at the term
        // that answers it: the pairing lives only in answer_id, and that is
        // what redaction removes before the manifest reaches a browser.
        $itemId = fn (array $term) => str_replace('term-', 'mi-', $term['id']);

        $bank = array_map(
            fn (array $term) => ['id' => $itemId($term), 'label' => $term['term']],
            $vocabulary
        );

        $slots = array_values(array_map(
            fn (int $index, array $term) => [
                'id' => 'ms-' . ($index + 1),
                'description' => $term['definition'],
                'answer_id' => $itemId($term),
            ],
            array_keys($vocabulary),
            $vocabulary
        ));

        $page->blocks()->create([
            'type' => 'matching',
            'position' => 1,
            'config' => [
                'instructions' => 'Match each welding term to its definition. Drag a term onto its definition, or tap a term and then tap its definition.',
                'bank' => $bank,
                'slots' => $slots,
                'shuffle' => true,
            ],
            'grading' => $this->fullGrading('all_correct', null, 10),
        ]);
    }

    private function pageWeldingDiagram(Lesson $lesson): void
    {
        $page = $this->makePage($lesson, 6, 'Label the Welding System', PageCompletionType::CompleteActivity, 8);

        $page->blocks()->create([
            'type' => 'image_labeling',
            'position' => 1,
            'config' => [
                'image_url' => self::DIAGRAM_URL,
                'image_alt' => 'Cross-section diagram of a weld in progress: an electrode above two steel plates, an arc and molten pool at the seam, a filler rod entering from the right, and a finished bead along the completed portion of the joint.',
                'long_description' => 'The diagram shows two flat steel plates meeting at a seam. A vertical electrode descends from the top center, producing a bright arc just above the plates. Beneath the arc, a glowing orange pool of molten metal sits over the seam. A thin filler rod enters at an angle from the upper right, feeding into the pool. To the left of the pool, the metal has already cooled into a ridged bead that resembles a row of stacked coins.',
                'instructions' => 'Drag each component name onto its numbered point on the diagram, or tap a name and then tap the point.',
                // Coordinates are measured against the current diagram asset
                // (934x465). They will be finalized visually in the Phase 5
                // hotspot editor. Arc and weld pool are nearly stacked in the
                // artwork, so they are offset horizontally to keep their tap
                // targets from colliding.
                //
                // Hotspot ids are positional, matching the point number the
                // student already sees. Naming them after the component they
                // expect would pair each hotspot with its own bank item id in
                // plain sight, which is exactly what stripping answer_id is
                // meant to prevent.
                'hotspots' => [
                    [
                        'id' => 'hs-1',
                        'number' => 1,
                        'x_pct' => 48.0,
                        'y_pct' => 24.0,
                        'answer_id' => 'bank-electrode',
                        'description' => 'Vertical tool at the top that carries electricity and creates the arc.',
                    ],
                    [
                        'id' => 'hs-2',
                        'number' => 2,
                        'x_pct' => 53.0,
                        'y_pct' => 57.0,
                        'answer_id' => 'bank-arc',
                        'description' => 'Bright glow beneath the electrode tip that generates intense heat.',
                    ],
                    [
                        'id' => 'hs-3',
                        'number' => 3,
                        'x_pct' => 64.0,
                        'y_pct' => 52.0,
                        'answer_id' => 'bank-filler',
                        'description' => 'Angled metal rod fed into the heat that melts to strengthen the connection.',
                    ],
                    [
                        'id' => 'hs-4',
                        'number' => 4,
                        'x_pct' => 44.0,
                        'y_pct' => 69.0,
                        'answer_id' => 'bank-weld-pool',
                        'description' => 'Glowing liquid puddle where base and filler metals fuse.',
                    ],
                    [
                        'id' => 'hs-5',
                        'number' => 5,
                        'x_pct' => 30.0,
                        'y_pct' => 72.0,
                        'answer_id' => 'bank-weld-bead',
                        'description' => 'Cooled ripple seam to the left of the pool joining the plates.',
                    ],
                    [
                        'id' => 'hs-6',
                        'number' => 6,
                        'x_pct' => 15.0,
                        'y_pct' => 73.0,
                        'answer_id' => 'bank-base-metal',
                        'description' => 'Flat steel plates being connected.',
                    ],
                    [
                        'id' => 'hs-7',
                        'number' => 7,
                        'x_pct' => 48.0,
                        'y_pct' => 78.0,
                        'answer_id' => 'bank-joint',
                        'description' => 'Seam line under the weld pool where the two plates meet.',
                    ],
                ],
                'bank' => [
                    ['id' => 'bank-electrode', 'label' => 'Electrode'],
                    ['id' => 'bank-arc', 'label' => 'Arc'],
                    ['id' => 'bank-filler', 'label' => 'Filler Material'],
                    ['id' => 'bank-weld-pool', 'label' => 'Weld Pool'],
                    ['id' => 'bank-weld-bead', 'label' => 'Weld Bead'],
                    ['id' => 'bank-base-metal', 'label' => 'Base Metal'],
                    ['id' => 'bank-joint', 'label' => 'Joint'],
                ],
            ],
            'grading' => $this->fullGrading('all_correct', null, 7),
        ]);
    }

    private function pageEngineeringChallenge(Lesson $lesson): void
    {
        $page = $this->makePage($lesson, 7, 'Engineering Challenge', PageCompletionType::SubmitRequired, 10);

        $page->blocks()->create([
            'type' => 'static_table',
            'position' => 1,
            'config' => [
                'caption' => 'Engineering comparison chart: five methods of joining materials',
                'first_column_is_header' => true,
                'headers' => ['Method', 'Strength', 'Durability', 'Best uses', 'Weaknesses'],
                'rows' => [
                    ['Tape', 'Low', 'Very low', 'Temporary light items, paper crafts', 'Fails under weight, heat, or moisture'],
                    ['Glue', 'Low to moderate', 'Low to moderate', 'Wood crafts, lightweight household repairs', 'Brittle under stress, not waterproof for heavy loads'],
                    ['Nails', 'Moderate', 'Moderate', 'Wood framing, fencing, basic construction', 'Can loosen over time, limited in metal applications'],
                    ['Bolts', 'High', 'High', 'Steel frames, machinery, removable connections', 'Can loosen with vibration, requires maintenance'],
                    ['Welding', 'Highest', 'Highest', 'Steel structures, ships, bridges, manufacturing', 'Permanent, requires skill and equipment'],
                ],
            ],
        ]);

        $page->blocks()->create([
            'type' => 'callout',
            'position' => 2,
            'config' => [
                'style' => 'info',
                'heading' => 'Careers behind this decision',
                'html' => '<p>Engineers at Newport News Shipbuilding and Norfolk Naval Shipyard make this exact call every day. Careers in this field include <strong>Structural Welder</strong>, <strong>Underwater Welder</strong>, <strong>Robotic Welding Technician</strong>, and <strong>Certified Welding Inspector</strong>.</p>',
            ],
        ]);

        $page->blocks()->create([
            'type' => 'cer',
            'position' => 3,
            'config' => [
                'scenario_html' => '<p>You are a shipyard engineer at <strong>Newport News Shipbuilding</strong>. A cargo ship has returned to dry dock with a damaged steel support beam in its hull. The repair must withstand immense ocean saltwater pressure, constant engine vibration, and heavy freight carried across the Atlantic Ocean.</p>'
                    .'<p>Your options are <strong>glue</strong>, <strong>bolts</strong>, or <strong>welding</strong>. Use the comparison chart above as your evidence.</p>',
                'fields' => [
                    [
                        'id' => 'claim',
                        'label' => 'Claim: which repair method should engineers select?',
                        'placeholder' => 'State your recommendation clearly and directly.',
                        'min_length' => 40,
                    ],
                    [
                        'id' => 'evidence',
                        'label' => 'Evidence: what facts from the comparison chart support your choice?',
                        'placeholder' => 'Reference strength, durability, and how each method behaves in water and under vibration.',
                        'min_length' => 80,
                    ],
                    [
                        'id' => 'reasoning',
                        'label' => 'Reasoning: why does your evidence prove this is the safest and most reliable solution at sea?',
                        'placeholder' => 'Use the words fusion, permanent, thermal energy, and stress.',
                        'min_length' => 80,
                    ],
                ],
            ],
            'grading' => $this->fullGrading('completion_only', null, 15),
        ]);
    }

    private function pageQuiz(Lesson $lesson): void
    {
        // Read-aloud stays enabled: this assesses welding knowledge, not
        // reading comprehension, so it is an instructional accommodation.
        $page = $this->makePage($lesson, 8, 'Show What You Know', PageCompletionType::PassActivity, 10, [
            'allow_read_aloud' => true,
            'allow_back_navigation' => false,
        ]);

        $guide = 'GO TEC WEL 6.1.1 teacher guide';

        $page->blocks()->create([
            'type' => 'quiz',
            'position' => 1,
            'config' => [
                'shuffle_questions' => false,
                'questions' => [
                    [
                        'id' => 'q-define-welding',
                        'prompt' => 'Welding is best described as which of the following?',
                        'options' => [
                            ['id' => 'q1-a', 'text' => 'A way to clamp two metals together with fasteners'],
                            ['id' => 'q1-b', 'text' => 'A manufacturing process that uses heat, pressure, or both to permanently join materials'],
                            ['id' => 'q1-c', 'text' => 'A type of glue used on metal'],
                            ['id' => 'q1-d', 'text' => 'A method for painting steel'],
                        ],
                        'answer_id' => 'q1-b',
                        'feedback' => 'Welding permanently joins materials using heat, pressure, or both. Fasteners only hold separate pieces together.',
                        'source_ref' => [
                            'page' => $guide.', section 2: Curriculum Standards and Objectives',
                            'excerpt' => 'Manufacturing process using heat, pressure, or both to permanently join materials.',
                        ],
                    ],
                    [
                        'id' => 'q-arc-temperature',
                        'prompt' => 'How hot can a welding arc get?',
                        'options' => [
                            ['id' => 'q2-a', 'text' => 'About 250 degrees Fahrenheit'],
                            ['id' => 'q2-b', 'text' => 'About 1,000 degrees Fahrenheit'],
                            ['id' => 'q2-c', 'text' => 'Up to 6,500 degrees Fahrenheit'],
                            ['id' => 'q2-d', 'text' => 'About 100,000 degrees Fahrenheit'],
                        ],
                        'answer_id' => 'q2-c',
                        'feedback' => 'The arc reaches roughly 6,500 degrees Fahrenheit, far above the 2,500 degrees at which structural steel melts.',
                        'source_ref' => [
                            'page' => $guide.', section 1: Thermal Energy and Arc Dynamics',
                            'excerpt' => 'An electrical arc generates thermal energy reaching temperatures up to 6,500 degrees Fahrenheit.',
                        ],
                    ],
                    [
                        'id' => 'q-filler-role',
                        'prompt' => 'Which component melts into the weld pool to reinforce and strengthen the joint?',
                        'options' => [
                            ['id' => 'q3-a', 'text' => 'Base metal'],
                            ['id' => 'q3-b', 'text' => 'Filler material'],
                            ['id' => 'q3-c', 'text' => 'The clamp'],
                            ['id' => 'q3-d', 'text' => 'The joint'],
                        ],
                        'answer_id' => 'q3-b',
                        'feedback' => 'Filler material is added into the molten pool to fill gaps and add strength.',
                        'source_ref' => [
                            'page' => $guide.', section 1: Anatomy of a Weld',
                            'excerpt' => 'Additional alloy added directly into the weld pool to reinforce the joint, compensate for gaps, and enhance overall structural integrity.',
                        ],
                    ],
                    [
                        'id' => 'q-base-metal',
                        'prompt' => 'The original metal pieces being joined together are called the what?',
                        'options' => [
                            ['id' => 'q4-a', 'text' => 'Weld bead'],
                            ['id' => 'q4-b', 'text' => 'Electrode'],
                            ['id' => 'q4-c', 'text' => 'Base metal'],
                            ['id' => 'q4-d', 'text' => 'Filler material'],
                        ],
                        'answer_id' => 'q4-c',
                        'feedback' => 'The base metal is the original workpieces being permanently connected.',
                        'source_ref' => [
                            'page' => $guide.', section 1: Anatomy of a Weld',
                            'excerpt' => 'The primary metal workpieces being permanently joined.',
                        ],
                    ],
                    [
                        'id' => 'q-ship-hull-choice',
                        'prompt' => 'Why do shipbuilders choose welding instead of bolts for a ship hull?',
                        'options' => [
                            ['id' => 'q5-a', 'text' => 'Bolts are too expensive'],
                            ['id' => 'q5-b', 'text' => 'Vibration can loosen bolts over time, while a weld is a permanent waterproof fusion'],
                            ['id' => 'q5-c', 'text' => 'Welding is faster to undo for repairs'],
                            ['id' => 'q5-d', 'text' => 'Bolts rust instantly in saltwater'],
                        ],
                        'answer_id' => 'q5-b',
                        'feedback' => 'Constant vibration loosens mechanical fasteners. Welding fuses the hull into one continuous, waterproof piece.',
                        'source_ref' => [
                            'page' => $guide.', worksheet 3 answer key: CER Engineering Challenge',
                            'excerpt' => 'Adhesives fail under heat and water exposure, while bolts can loosen over time due to continuous mechanical vibration.',
                        ],
                    ],
                    [
                        'id' => 'q-bead-appearance',
                        'prompt' => 'A quality weld bead has a pattern that looks most like which of these?',
                        'options' => [
                            ['id' => 'q6-a', 'text' => 'A neat row of stacked coins'],
                            ['id' => 'q6-b', 'text' => 'A zigzag of tape'],
                            ['id' => 'q6-c', 'text' => 'A coiled spring'],
                            ['id' => 'q6-d', 'text' => 'Random splatter'],
                        ],
                        'answer_id' => 'q6-a',
                        'feedback' => 'A uniform, stacked-coin ripple pattern is the mark of a quality bead.',
                        'source_ref' => [
                            'page' => $guide.', section 1: Anatomy of a Weld',
                            'excerpt' => 'A quality bead displays a uniform pattern resembling stacked coins.',
                        ],
                    ],
                    [
                        'id' => 'q-strongest-method',
                        'prompt' => 'According to the engineering comparison chart, which joining method provides the highest strength and durability?',
                        'options' => [
                            ['id' => 'q7-a', 'text' => 'Glue'],
                            ['id' => 'q7-b', 'text' => 'Nails'],
                            ['id' => 'q7-c', 'text' => 'Bolts'],
                            ['id' => 'q7-d', 'text' => 'Welding'],
                        ],
                        'answer_id' => 'q7-d',
                        'feedback' => 'Welding rates highest for both strength and durability, creating a permanent, leak-proof structural bond.',
                        'source_ref' => [
                            'page' => $guide.', section 3: Material Joining Method Analysis',
                            'excerpt' => 'Welding: Highest strength and durability. Creates a permanent, continuous, leak-proof structural bond.',
                        ],
                    ],
                    [
                        'id' => 'q-electrode-role',
                        'prompt' => 'What is the job of the electrode?',
                        'options' => [
                            ['id' => 'q8-a', 'text' => 'To cool the metal down after welding'],
                            ['id' => 'q8-b', 'text' => 'To conduct electrical current and create the arc'],
                            ['id' => 'q8-c', 'text' => 'To hold the two plates in place'],
                            ['id' => 'q8-d', 'text' => 'To measure the temperature of the weld'],
                        ],
                        'answer_id' => 'q8-b',
                        'feedback' => 'The electrode conducts current to form the arc, and in many processes it also melts to supply filler material.',
                        'source_ref' => [
                            'page' => $guide.', section 1: Anatomy of a Weld',
                            'excerpt' => 'A specialized conductor through which electrical current flows to create the arc.',
                        ],
                    ],
                    [
                        'id' => 'q-weld-pool',
                        'prompt' => 'The weld pool is best described as which of the following?',
                        'options' => [
                            ['id' => 'q9-a', 'text' => 'The water used to cool the weld'],
                            ['id' => 'q9-b', 'text' => 'The molten zone where base and filler metals fuse'],
                            ['id' => 'q9-c', 'text' => 'A storage container for electrodes'],
                            ['id' => 'q9-d', 'text' => 'The finished seam after cooling'],
                        ],
                        'answer_id' => 'q9-b',
                        'feedback' => 'The weld pool is the localized puddle of liquid metal where fusion actually happens. The finished seam is the weld bead.',
                        'source_ref' => [
                            'page' => $guide.', section 1: Anatomy of a Weld',
                            'excerpt' => 'The localized area of liquid metal created by the arc where fusion occurs.',
                        ],
                    ],
                    [
                        'id' => 'q-industries',
                        'prompt' => 'Which set of industries depends heavily on welding?',
                        'options' => [
                            ['id' => 'q10-a', 'text' => 'Construction and shipbuilding'],
                            ['id' => 'q10-b', 'text' => 'Automotive and manufacturing'],
                            ['id' => 'q10-c', 'text' => 'Energy production and transportation'],
                            ['id' => 'q10-d', 'text' => 'All of the above'],
                        ],
                        'answer_id' => 'q10-d',
                        'feedback' => 'Welding is essential across construction, shipbuilding, automotive, energy production, transportation, and manufacturing.',
                        'source_ref' => [
                            'page' => $guide.', slide deck: Welding Applications',
                            'excerpt' => 'Welding is used in nearly every major industry.',
                        ],
                    ],
                ],
            ],
            'grading' => $this->fullGrading('min_score', 80, 100),
        ]);
    }

    private function pageResults(Lesson $lesson): void
    {
        $page = $this->makePage($lesson, 9, 'Lesson Complete', PageCompletionType::View, 3);

        $page->blocks()->create([
            'type' => 'rich_text',
            'position' => 1,
            'config' => [
                'html' => '<h2>Welding holds our world together</h2>'
                    .'<p>You can now explain what welding is, how thermal energy creates fusion between metals, and why engineers choose a permanent weld over glue or bolts when public safety depends on the joint.</p>'
                    .'<p>Bridges, ships, buildings, cars, and pipelines all depend on the skill of a welder.</p>',
            ],
        ]);

        $page->blocks()->create([
            'type' => 'callout',
            'position' => 2,
            'config' => [
                'style' => 'tip',
                'heading' => 'Where this leads',
                'html' => '<p>Students who complete GO TEC welding build skills that lead directly to careers at employers like Newport News Shipbuilding and Norfolk Naval Shipyard. AWS and industry certifications open doors to welder, inspector, and engineer pathways, all starting from the foundation you built today.</p>',
            ],
        ]);
    }
}
