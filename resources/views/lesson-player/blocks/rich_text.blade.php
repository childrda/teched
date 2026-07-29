@inject('richTextSegmenter', 'App\Services\RichTextSegmenter')

{{--
    The HTML was sanitized at publish time, so it is rendered as-is rather
    than escaped. tag() adds one data-speech-id per top-level element and per
    list item, using the same walk that produced this block's speech segments.
--}}
<div class="player-prose">{!! $richTextSegmenter->tag($config['html'] ?? '') !!}</div>
