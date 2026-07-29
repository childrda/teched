import './bootstrap';

import Alpine from 'alpinejs';

import { lessonPlayer } from './lesson-player/player';
import { placementActivity } from './lesson-player/placement-controller';

Alpine.data('lessonPlayer', lessonPlayer);
Alpine.data('placementActivity', placementActivity);

window.Alpine = Alpine;

Alpine.start();
