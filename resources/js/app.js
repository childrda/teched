import './bootstrap';

import Alpine from 'alpinejs';

import { hotspotEditor } from './authoring/hotspot-editor';
import { lessonPlayer } from './lesson-player/player';
import { placementActivity } from './lesson-player/placement-controller';
import { shortResponseActivity } from './lesson-player/short-response';
import { cerActivity } from './lesson-player/cer';
import { quizActivity } from './lesson-player/quiz';
import { installDragAutoScroll } from './lesson-player/drag-auto-scroll';

Alpine.data('hotspotEditor', hotspotEditor);
Alpine.data('lessonPlayer', lessonPlayer);
Alpine.data('placementActivity', placementActivity);
Alpine.data('shortResponseActivity', shortResponseActivity);
Alpine.data('cerActivity', cerActivity);
Alpine.data('quizActivity', quizActivity);

window.Alpine = Alpine;

Alpine.start();

// Native HTML5 drag does not auto-scroll the page reliably; edge scrolling
// while a drag is held keeps destinations below the fold reachable by mouse.
installDragAutoScroll();

