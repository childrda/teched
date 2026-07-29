import './bootstrap';

import Alpine from 'alpinejs';

import { lessonPlayer } from './lesson-player/player';
import { placementActivity } from './lesson-player/placement-controller';
import { shortResponseActivity } from './lesson-player/short-response';
import { cerActivity } from './lesson-player/cer';
import { quizActivity } from './lesson-player/quiz';

Alpine.data('lessonPlayer', lessonPlayer);
Alpine.data('placementActivity', placementActivity);
Alpine.data('shortResponseActivity', shortResponseActivity);
Alpine.data('cerActivity', cerActivity);
Alpine.data('quizActivity', quizActivity);

window.Alpine = Alpine;

Alpine.start();
