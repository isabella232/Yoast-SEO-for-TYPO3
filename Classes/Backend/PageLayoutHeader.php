<?php
namespace YoastSeoForTypo3\YoastSeo\Backend;

use TYPO3\CMS\Backend\Controller\PageLayoutController;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use YoastSeoForTypo3\YoastSeo\Service\LocaleService;
use YoastSeoForTypo3\YoastSeo\Service\UrlService;
use YoastSeoForTypo3\YoastSeo\Utility\JsonConfigUtility;
use YoastSeoForTypo3\YoastSeo\Utility\YoastUtility;

class PageLayoutHeader
{
    /**
     * @var array
     */
    protected $configuration = [
        'translations' => [
            'availableLocales' => [],
            'languageKeyToLocaleMapping' => []
        ],
        'menuActions' => [],
        'viewSettings' => []
    ];

    /**
     * @var \YoastSeoForTypo3\YoastSeo\Service\LocaleService
     */
    protected $localeService;

    /**
     * @var \YoastSeoForTypo3\YoastSeo\Service\UrlService
     */
    protected $urlService;

    /**
     * @var \TYPO3\CMS\Core\Page\PageRenderer
     */
    protected $pageRenderer;

    /**
     * Initialize the page renderer
     */
    public function __construct()
    {
        if (array_key_exists('yoast_seo', $GLOBALS['TYPO3_CONF_VARS']['EXTCONF'])
            && is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['yoast_seo'])
        ) {
            ArrayUtility::mergeRecursiveWithOverrule(
                $this->configuration,
                $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['yoast_seo']
            );
        }

        $this->localeService = GeneralUtility::makeInstance(LocaleService::class, $this->configuration);
        $this->urlService = GeneralUtility::makeInstance(UrlService::class);
        $this->pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
    }

    /**
     * @param array|null $params
     * @param null  $parentObj
     * @return string
     */
    public function render(array $params = null, $parentObj = null)
    {
        $moduleData = BackendUtility::getModuleData(['language'], [], 'web_layout');
        $pageId = (int)GeneralUtility::_GET('id');
        $currentPage = $this->getCurrentPage($pageId, $moduleData, $parentObj);

        if (!YoastUtility::snippetPreviewEnabled($pageId, $currentPage)) {
            return '';
        }

        $allowedDoktypes = YoastUtility::getAllowedDoktypes();
        if (is_array($currentPage) &&
            array_key_exists('doktype', $currentPage) &&
            in_array((int)$currentPage['doktype'], $allowedDoktypes, true)
        ) {
            $publicResourcesPath =
                PathUtility::getAbsoluteWebPath(ExtensionManagementUtility::extPath('yoast_seo')) . 'Resources/Public/';

            $config = [
                'urls' => [
                    'workerUrl' => $publicResourcesPath . '/JavaScript/dist/worker.js',
                    'previewUrl' => $this->urlService->getPreviewUrl($pageId, (int)$moduleData['language']),
                    'saveScores' => $this->urlService->getSaveScoresUrl(),
                    'prominentWords' => $this->urlService->getUrlForType(1539541406),
                ],
                'useKeywordDistribution' => YoastUtility::isPremiumInstalled(),
                'useRelevantWords' => YoastUtility::isPremiumInstalled(),
                'isCornerstoneContent' => (bool)$currentPage['tx_yoastseo_cornerstone'],
                'focusKeyphrase' => [
                    'keyword' => (string)$currentPage['tx_yoastseo_focuskeyword'],
                    'synonyms' => (string)$currentPage['tx_yoastseo_focuskeyword_synonyms'],
                ],
                'labels' => $this->localeService->getLabels(),
                'fieldSelectors' => [],
                'translations' => $this->localeService->getTranslations(),
                'data' => [
                    'table' => 'pages',
                    'uid' => $pageId,
                    'languageId' => (int)$moduleData['language']
                ],
            ];
            $jsonConfigUtility = GeneralUtility::makeInstance(JsonConfigUtility::class);
            $jsonConfigUtility->addConfig($config);

            $this->pageRenderer->addJsInlineCode('yoast-json-config', $jsonConfigUtility->render());

            if (YoastUtility::inProductionMode() === true) {
                $this->pageRenderer->loadRequireJsModule('TYPO3/CMS/YoastSeo/dist/plugin');
            } else {
                $this->pageRenderer->addHeaderData('<script type="text/javascript" src="https://localhost:3333/typo3conf/ext/yoast_seo/Resources/Public/JavaScript/dist/plugin.js" async></script>');
            }

            $this->pageRenderer->loadRequireJsModule('TYPO3/CMS/YoastSeo/yoastModal');
            $this->pageRenderer->addCssFile('EXT:yoast_seo/Resources/Public/CSS/yoast.min.css');

            return $this->getReturnHtml();
        }
        return '';
    }

    /**
     * @param int $pageId
     * @param array $moduleData
     * @param object $parentObj
     * @return array|null
     */
    protected function getCurrentPage(int $pageId, array $moduleData, object $parentObj): ?array
    {
        $currentPage = null;

        if ($parentObj instanceof PageLayoutController && $pageId > 0) {
            if ((int)$moduleData['language'] === 0) {
                $currentPage = BackendUtility::getRecord(
                    'pages',
                    $pageId
                );
            } elseif ((int)$moduleData['language'] > 0) {
                $overlayRecords = BackendUtility::getRecordLocalization(
                    'pages',
                    $pageId,
                    (int)$moduleData['language']
                );

                if (is_array($overlayRecords) && array_key_exists(0, $overlayRecords) && is_array($overlayRecords[0])) {
                    $currentPage = $overlayRecords[0];
                }
            }
        }
        return $currentPage;
    }

    /**
     * @return string
     */
    protected function getReturnHtml(): string
    {
        $premiumText = $this->getPremiumText();
        $targetElementId = uniqid('_YoastSEO_panel_');

        $returnHtml = '
                <div class="yoast-seo-score-bar">
                    <span class="yoast-seo-score-bar--analysis yoast-seo-page" data-yoast-analysis-type="readability"></span>
                    <span class="yoast-seo-score-bar--analysis yoast-seo-page" data-yoast-analysis-type="seo"></span>
                </div>
                <div class="yoast-seo-snippet-header">
                    ' . $premiumText . '
                    <div class="yoast-snippet-header-label">Yoast SEO</div>
                </div>';

        $returnHtml .= '
            <input id="focusKeyword" style="display: none" />
            <div id="' . $targetElementId . '" class="t3-grid-cell yoast-seo yoast-seo-snippet-preview-styling" style="padding: 10px;" data-yoast-snippetpreview>
                <!-- ' . $targetElementId . ' -->
                <div class="spinner">
                  <div class="bounce bounce1"></div>
                  <div class="bounce bounce2"></div>
                  <div class="bounce bounce3"></div>
                </div>
            </div>
            <div data-yoast-analysis="readability" id="YoastPageHeaderAnalysisReadability" data-yoast-subtype="" class="hidden yoast-analysis"></div>
            <div data-yoast-analysis="seo" id="YoastPageHeaderAnalysisSeo" data-yoast-subtype="" class="hidden yoast-analysis"></div>
            ';

        return $returnHtml;
    }

    /**
     * Get premium text
     *
     * @return string
     */
    protected function getPremiumText(): string
    {
        if (!YoastUtility::isPremiumInstalled()) {
            return '
                <div class="yoast-seo-snippet-header-premium">
                    <a target="_blank" rel="noopener noreferrer" href="' . YoastUtility::getYoastLink('Go premium', 'pagemodule-snippetpreview') . '">
                        <i class="fa fa-star"></i>' . $GLOBALS['LANG']->sL('LLL:EXT:yoast_seo/Resources/Private/Language/BackendModule.xlf:goPremium') . '
                    </a>
                </div>';
        }
        return '';
    }
}
