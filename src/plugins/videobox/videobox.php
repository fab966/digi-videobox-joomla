<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.Videobox
 * @copyright   Copyright (C) 2016 HitkoDev + @2025 Fab966
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Joomla\Plugin\System\Videobox;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;

if (!\class_exists('\VideoboxVideobox')) {
    // Fallback: prova a caricare la libreria se non è già autocaricata
    if (\class_exists('\JLoader')) {
        \JLoader::discover('Videobox', JPATH_LIBRARIES . '/videobox');
    }
}

/**
 * Videobox System Plugin (Joomla 4)
 */
class Videobox extends CMSPlugin
{
    /**
     * Cache dei set di proprietà
     *
     * @var array|null
     */
    private $sets;

    /**
     * Ritorna i set di proprietà configurati nel plugin
     *
     * @return array
     */
    private function getSets()
    {
        if ($this->sets) {
            return $this->sets;
        }

        $sets = \json_decode(\json_encode($this->params->get('property_sets', [])), true);

        $s2  = [];
        $def = false;

        foreach ($sets as $set) {
            $key = $set['key'] ?? '';
            unset($set['key']);
            $s2[$key] = $set;

            if (!$def || $key === 'default') {
                $def = $set;
            }
        }

        $s2['default'] = $def ? $def : [];

        $s2['default']['color']  = $this->params->get('color', '');
        $s2['default']['tColor'] = $this->params->get('tColor', '');
        $s2['default']['hColor'] = $this->params->get('hColor', '');
        $s2['default']['bgColor'] = $this->params->get('bgColor', '');

        $this->sets = $s2;

        return $this->sets;
    }

    /**
     * Carica gli asset in head
     */
    public function onBeforeCompileHead()
    {
        $app      = Factory::getApplication();
        $document = Factory::getDocument();

        if ($app->isClient('site') && \method_exists($document, 'addCustomTag')) {
            $videobox = new \VideoboxVideobox();
            $sets     = $this->getSets();
            $videobox->setConfig($sets['default']);
            $videobox->loadAssets();
        }
    }

    /**
     * Prepara i player prima del render
     */
    public function onBeforeRender()
    {
        $app      = Factory::getApplication();
        $document = Factory::getDocument();

        if ($app->isClient('site') && \method_exists($document, 'addCustomTag')) {
            $videobox = new \VideoboxVideobox();
            $sets     = $this->getSets();
            $videobox->setConfig($sets['default']);

            PluginHelper::importPlugin('videobox');
            // In J4 usiamo triggerEvent sull'application
            $players = $app->triggerEvent('renderVbPlayer', [$videobox]);
        }
    }

    /**
     * Sostituisce i tag {videobox}...{/videobox} nel body finale
     */
    public function onAfterRender()
    {
        $app      = Factory::getApplication();
        $document = Factory::getDocument();

        $instances = [
            'tags'    => [],
            'outputs' => [],
        ];

        if ($app->isClient('site') && \method_exists($document, 'addCustomTag')) {
            $videobox = new \VideoboxVideobox();

            $content = $app->getBody();
            \preg_match_all(
                "/\{\s*videobox\s*([\@\?]?\s*[^`\}]*([^`\}]*`[^`]*?`)*)\s*\}(.*?){\s*\/\s*videobox\s*\}/ism",
                $content,
                $matches,
                PREG_SET_ORDER
            );

            $sets = $this->getSets();

            foreach ($matches as $match) {
                $open   = \trim(\strip_tags(\html_entity_decode($match[1])));
                $videos = \trim(\strip_tags(\html_entity_decode($match[\count($match) - 1])));

                $set   = '';
                $props = [];

                if ($open) {
                    $l = 0;

                    if (isset($open[$l]) && $open[$l] === '@') {
                        $l++;   // salta '@'

                        \preg_match('/[\s\?]/ism', $open, $m, PREG_OFFSET_CAPTURE, $l);  // spazio o '?' fine chiave set
                        $r = \count($m) > 0 ? $m[0][1] : \strlen($open);                // se non c'è, fino alla fine

                        $set = \substr($open, $l, $r - $l);
                        $l   = $r; // sposta oltre la chiave
                    }

                    if ($l < \strlen($open)) {
                        \preg_match('/\?\s*/ism', $open, $m, PREG_OFFSET_CAPTURE, $l); // proprietà dopo '?'

                        if (\count($m) > 0) {
                            $l = \strlen($m[0][0]) + $m[0][1];

                            \preg_match_all(
                                "/\&\s*([^=`]*)\s*=\s*`([^`]*)`/ism",
                                $open,
                                $m,
                                PREG_SET_ORDER,
                                $l
                            ); // estrae &key=`value`

                            foreach ($m as $prop) {
                                $props[$prop[1]] = $prop[2];
                            }
                        }
                    }
                }

                $props['videos']     = $videos;
                $instances['tags'][] = $match[0];
                $instances['outputs'][] = $this->generateOutput(
                    $videobox,
                    \array_merge($sets['default'], $sets[$set] ?? [], $props)
                );
            }
        }

        if (\count($instances['tags']) > 0) {
            $app->setBody(\str_replace($instances['tags'], $instances['outputs'], $app->getBody()));
        }
    }

    /**
     * Genera l'output HTML per i tag Videobox
     *
     * @param  \VideoboxVideobox $videobox
     * @param  array             $scriptProperties
     * @return string|null
     */
    private function generateOutput($videobox, $scriptProperties)
    {
        $videobox->setConfig($scriptProperties);
        $scriptProperties['color'] = $videobox->config['color'];

        $videos = \explode('|,', $scriptProperties['videos']);
        $processors = $videobox->getProcessors();

        $vid = [];
        foreach ($videos as $key => $video) {
            $video = \explode('|', $video);
            $title = '';
            if (isset($video[1])) {
                $title = \trim($video[1]);
            }
            $title = $videobox->htmldec($title);
            $title = $videobox->htmlenc($title);
            $video = \explode('#', $video[0]);
            $id    = \trim($video[0]);
            $start = 0;
            $end   = 0;

            if (\count($video) > 1) {
                $video = \explode('-', \trim($video[\count($video) - 1]));
                if (\count($video) > 0 && \is_numeric(\str_replace(':', '', \trim($video[0])))) {
                    $off = \explode(':', \trim($video[0]));
                    foreach ($off as $off1) {
                        $start = $start * 60 + $off1;
                    }
                }
                if (\count($video) > 1 && \is_numeric(\str_replace(':', '', \trim($video[1])))) {
                    $off = \explode(':', \trim($video[1]));
                    foreach ($off as $off1) {
                        $end = $end * 60 + $off1;
                    }
                }
            }

            $prop = \array_merge($scriptProperties, [
                'id'    => $id,
                'title' => $title,
                'start' => $start,
                'end'   => $end,
            ]);

            $v = $videobox->getVideo([
                'id'    => $id,
                'title' => $title,
                'start' => $start,
                'end'   => $end,
            ]);

            if ($v) {
                $vid[] = $v;
            }
        }
        $videos = $vid;

        if (\count($videos) < 1) {
            return null;
        }

        if (!isset($scriptProperties['display']) || !$scriptProperties['display']) {
            $scriptProperties['display'] = \count($videos) > 1
                ? $scriptProperties['multipleDisplay']
                : $scriptProperties['singleDisplay'];
        }

        if ($scriptProperties['display'] === 'link') {
            $scriptProperties['display'] = 'links';
        }
        if ($scriptProperties['display'] === 'links' && $scriptProperties['player'] === 'vbinline') {
            $scriptProperties['player'] = 'videobox';
        }

        unset($scriptProperties['multipleDisplay'], $scriptProperties['singleDisplay']);

        $vbOptions = [];
        foreach ($scriptProperties as $k => $v) {
            if (\substr($k, 0, 3) === 'js.') {
                $parts = \array_filter(\array_map('trim', \explode('.', $k)));
                $o     = &$vbOptions;

                if (\is_numeric($v)) {
                    $v = (float) $v;
                }

                for ($i = 1; $i < \count($parts); $i++) {
                    $part = $parts[$i];
                    if (\is_numeric($part)) {
                        $part = (float) $part;
                    }

                    if ($i === \count($parts) - 1) {
                        $o[$part] = $v;
                    } else {
                        if (!isset($o[$part])) {
                            $o[$part] = [];
                        }
                        $o = &$o[$part];
                    }
                }
            }
        }

        $vbOptions['width']  = (float) $scriptProperties['pWidth'];
        $vbOptions['height'] = (float) $scriptProperties['pHeight'];

        if (isset($scriptProperties['style'])) {
            $vbOptions['style'] = $scriptProperties['style'];
        }
        if (isset($scriptProperties['class'])) {
            $vbOptions['class'] = $scriptProperties['class'];
        }

        if (\count($videos) > 1) {
            $tpl        = $scriptProperties['display'] === 'links' ? $scriptProperties['linkTpl'] : $scriptProperties['thumbTpl'];
            $start      = 0;
            $pagination = '';

            if ($scriptProperties['display'] === 'gallery') {
                $videobox->gallery++;
                $start = $videobox->getPage();
                $scriptProperties['gallery_number'] = $videobox->gallery;
                $scriptProperties['gallery_page']   = $start;
                $pagination = $videobox->pagination(\count($videos), $start, $scriptProperties['perPage']);
                $start = $start * $scriptProperties['perPage'];
            }

            if ($scriptProperties['player'] === 'vbinline'
                && ($scriptProperties['display'] === 'gallery' || $scriptProperties['display'] === 'slider')) {
                $scriptProperties['pWidth']  = $scriptProperties['tWidth'];
                $scriptProperties['pHeight'] = $scriptProperties['tHeight'];
                $vbOptions['width']          = (float) $scriptProperties['pWidth'];
                $vbOptions['height']         = (float) $scriptProperties['pHeight'];
            }

            \ksort($scriptProperties);
            $propHash = 'Vb_gallery_' . \md5(\serialize($scriptProperties));
            $content  = $videobox->getCache($propHash);

            if (!$content) {
                $n        = 0;
                $content  = '';
                $props    = [
                    'vbOptions' => \htmlspecialchars(\json_encode($vbOptions)),
                    'rel'       => $scriptProperties['player'],
                    'pWidth'    => $scriptProperties['pWidth'],
                    'pHeight'   => $scriptProperties['pHeight'],
                ];
                $filtered = [];

                foreach ($videos as $video) {
                    $n++;
                    if ($start > 0 && $n <= $start) {
                        continue;
                    }
                    $filtered[] = [
                        'title'    => $video->getTitle(),
                        'linkText' => $video->getTitle(true),
                        'link'     => $video->getPlayerLink(true),
                        'thumb'    => $videobox->videoThumbnail($video, $scriptProperties['display'] === 'flow'),
                    ];
                    if ($scriptProperties['display'] === 'gallery' && $n === ($start + $scriptProperties['perPage'])) {
                        break;
                    }
                }

                $maxR = 0;
                $maxW = $scriptProperties['tWidth'];
                foreach ($filtered as $video) {
                    $r = $video['thumb'][1] / $video['thumb'][2];
                    if ($r > $maxR) {
                        $maxR = $r;
                    }
                }

                $minR = 0.6;
                foreach ($filtered as $video) {
                    $r = $video['thumb'][1] / ($maxR * $video['thumb'][2]);
                    if ($r && $r < $minR) {
                        $minR = $r;
                    }
                }
                $minR = 1 - \log($minR);

                $n = 0;
                foreach ($filtered as $video) {
                    $v = $videobox->parseTemplate(
                        $tpl,
                        \array_merge(
                            $props,
                            $video,
                            [
                                'thumb'  => $video['thumb'][0],
                                'tWidth' => $video['thumb'][1],
                                'tHeight'=> $video['thumb'][2],
                            ]
                        )
                    );

                    switch ($scriptProperties['display']) {
                        case 'links':
                            $v = ($n === 0 ? '' : $scriptProperties['delimiter']) . $v;
                            break;

                        case 'slider':
                            $r = $video['thumb'][1] / ($maxR * $video['thumb'][2]);
                            $b = 0.25 * $r * $maxW * $minR;
                            $v = $videobox->parseTemplate($scriptProperties['sliderItemTpl'], ['content' => $v, 'ratio' => $r, 'basis' => $b]);
                            break;

                        default:
                            $scriptProperties['display'] = 'gallery';
                            $r = $video['thumb'][1] / ($maxR * $video['thumb'][2]);
                            $b = 0.25 * $r * $maxW * $minR;
                            $v = $videobox->parseTemplate($scriptProperties['galleryItemTpl'], ['content' => $v, 'ratio' => $r, 'basis' => $b]);
                            break;
                    }

                    $n++;
                    $content .= $v;
                }

                $b = 0.25 * $maxW * $minR;
                if ($scriptProperties['display'] === 'gallery') {
                    for ($n = 0; $n < 10; $n++) {
                        $v = $videobox->parseTemplate($scriptProperties['galleryItemTpl'], ['ratio' => 1, 'basis' => $b]);
                        $content .= $v;
                    }
                }

                $videobox->setCache($propHash, $content);
            }

            switch ($scriptProperties['display']) {
                case 'links':
                    return $content;

                case 'slider':
                    return $videobox->parseTemplate($scriptProperties['sliderTpl'], ['content' => $content, 'basis' => $scriptProperties['tWidth'] / 2]);

                default:
                    return $videobox->parseTemplate($scriptProperties['galleryTpl'], ['content' => $content, 'pagination' => $pagination]);
            }
        } else {
            $autoPlay = isset($scriptProperties['autoPlay'])
                && $scriptProperties['autoPlay']
                && $scriptProperties['display'] === 'player'
                && (!isset($videobox->autoPlay) || !$videobox->autoPlay);

            $scriptProperties['autoPlay'] = $autoPlay;

            if ($autoPlay) {
                $videobox->autoPlay = true;
            }

            \ksort($scriptProperties);
            $propHash = 'Vb_video_' . \md5(\serialize($scriptProperties));
            $data     = $videobox->getCache($propHash);

            if ($data) {
                return $data;
            }

            $video = $videos[0];
            $props = \array_merge(
                [
                    'vbOptions' => \htmlspecialchars(\json_encode($vbOptions)),
                    'rel'       => $scriptProperties['player'],
                    'pWidth'    => $scriptProperties['pWidth'],
                    'pHeight'   => $scriptProperties['pHeight'],
                    'tWidth'    => $scriptProperties['tWidth'],
                    'tHeight'   => $scriptProperties['tHeight'],
                ],
                [
                    'title' => $video->getTitle(),
                    'link'  => $video->getPlayerLink(\in_array($scriptProperties['display'], ['box', 'link', 'links'], true) || $autoPlay),
                    'ratio' => (100 * $scriptProperties['pHeight'] / $scriptProperties['pWidth']),
                ]
            );

            switch ($scriptProperties['display']) {
                case 'links':
                    $props['linkText'] = isset($linkText) ? \trim($linkText) : $video->getTitle(true);
                    $v = $videobox->parseTemplate($scriptProperties['linkTpl'], $props);
                    break;

                case 'box':
                    $thumb = $videobox->videoThumbnail($video);
                    $v = $videobox->parseTemplate(
                        $scriptProperties['boxTpl'],
                        \array_merge($props, ['thumb' => $thumb[0], 'tWidth' => $thumb[1], 'tHeight' => $thumb[2]])
                    );
                    break;

                default:
                    $v = $videobox->parseTemplate($scriptProperties['playerTpl'], $props);
                    break;
            }

            $videobox->setCache($propHash, $v);

            return $v;
        }
    }
}
