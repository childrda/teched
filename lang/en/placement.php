<?php

/**
 * Everything a placement activity says to a student.
 *
 * Read-aloud exists partly for English-language learners, so the students
 * these activities serve are the ones most likely to need another language.
 * The renderers pass these through to the Alpine controller, which keeps no
 * student-facing wording of its own.
 */
return [
    // Live-region announcements. :label and :displaced are item names,
    // :slot is a row or point name, :count is the number of slots.
    'picked_up' => ':label picked up',
    'placed_at' => ':label placed at :slot',
    'moved_to' => ':label moved to :slot',
    'placed_over' => ':label placed at :slot, :displaced returned to the bank',
    'returned' => ':label returned to the bank',
    'cancelled' => 'Selection cancelled',
    'select_first' => 'Select an item before choosing a slot',
    'complete' => 'All :count items placed',
    'reset' => 'Activity reset',

    // Why Continue is unavailable, shown by the page gate.
    'gate' => 'Place every item to continue.',

    // Visible labels and headings.
    'bank_heading' => 'Items to place',
    'bank_empty' => 'Every item has been placed.',
    'bank_hint' => 'Tap an item, then tap where it goes. You can also drag an item with a mouse.',
    'empty_slot' => 'Place an item',
    'reset_activity' => 'Start over',
    'image_description' => 'Image description',
    'rows_heading' => 'Descriptions',
    'points_heading' => 'Numbered points',
    'diagram_heading' => 'Diagram',

    // Slot names, and the accessible names built from them.
    'row' => 'Row :number',
    'point' => 'Point :number',
    'slot_empty' => ':slot: :description Empty.',
    'slot_filled' => ':slot: :description :label placed.',

    // A bank item is named by its label alone, unless two items share one:
    // then a number keeps them apart, in the accessible name and in every
    // announcement about them.
    'item_repeated' => ':label (:number)',
];
