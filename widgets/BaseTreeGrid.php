<?php

namespace dkhlystov\widgets;

use Yii;
use Closure;
use yii\base\InvalidConfigException;
use yii\base\Widget;
use yii\data\ActiveDataProvider;
use yii\grid\DataColumn;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\Url;
use yii\i18n\Formatter;
use yii\web\JsExpression;

/**
 * Base class for widgets displaying data as tree with columns
 * 
 * Based on yii\grid\GridView, but not extends it because no needs in features like sorting, paging
 * and filtering data.
 * 
 * @author Dmitry Khlystov <dkhlystov@gmail.com>
 */
abstract class BaseTreeGrid extends Widget {

	/**
     * @var array the HTML attributes for the container tag of the tree grid.
     * The "tag" element specifies the tag name of the container element and defaults to "div".
     * @see \yii\helpers\Html::renderTagAttributes() for details on how attributes are being rendered.
     */
    public $options = ['class' => 'treegrid'];
	/**
     * @see yii\widgets\BaseListView::$dataProvider
     */
    public $dataProvider;
	/**
     * @see yii\widgets\BaseListView::$emptyText
     */
    public $emptyText;
    /**
     * @see yii\widgets\BaseListView::$emptyTextOptions
     */
    public $emptyTextOptions = ['class' => 'empty'];
    /**
     * @see yii\grid\GridView::$dataColumnClass
     */
    public $dataColumnClass;
	/**
     * @see yii\grid\GridView::$tableOptions
	 */
	public $tableOptions = ['class' => 'table table-bordered'];
	/**
     * @see yii\grid\GridView::$headerRowOptions
     */
    public $headerRowOptions = [];
    /**
     * @see yii\grid\GridView::$footerRowOptions
     */
    public $footerRowOptions = [];
    /**
     * @see yii\grid\GridView::$rowOptions
     */
    public $rowOptions = [];
    /**
     * @see yii\grid\GridView::$beforeRow
     */
    public $beforeRow;
    /**
     * @see yii\grid\GridView::$afterRow
     */
    public $afterRow;
    /**
     * @see yii\grid\GridView::$showHeader
     */
    public $showHeader = true;
    /**
     * @see yii\grid\GridView::$showFooter
     */
    public $showFooter = false;
    /**
     * @see yii\grid\GridView::$formatter
     */
    public $formatter;
    /**
     * @see yii\grid\GridView::$columns
     */
    public $columns = [];
    /**
     * @see yii\grid\GridView::$emptyCell
     */
    public $emptyCell = '&nbsp;';
	/**
	 * @var array with this options initializes TreeGrid plugin for jQuery.
	 */
	public $pluginOptions = [];
	/**
	 * @var boolean if true, widget tries to display tree with dynamic data loading. When widget renderings, it shows first level of nodes. Next level of nodes loads when needed (node has been expanded).
	 */
	public $lazyLoad = true;
	/**
	 * @var boolean if true, root nodes will not showed when lazyLoad is enabled.
	 */
	public $showRoot = false;

	/**
     * Initializes the grid view.
     * This method will initialize required property values and instantiate [[columns]] objects.
     */
	public function init() {
		parent::init();

		if ($this->dataProvider === null) {
            throw new InvalidConfigException('The "dataProvider" property must be set.');
        }
        if ($this->emptyText === null) {
            $this->emptyText = Yii::t('yii', 'No results found.');
        }
        if (!isset($this->options['id'])) {
            $this->options['id'] = $this->getId();
        }
        if ($this->formatter === null) {
            $this->formatter = Yii::$app->getFormatter();
        } elseif (is_array($this->formatter)) {
            $this->formatter = Yii::createObject($this->formatter);
        }
        if (!$this->formatter instanceof Formatter) {
            throw new InvalidConfigException('The "formatter" property must be either a Format object or a configuration array.');
        }

        $this->initDataProvider();

        $this->initColumns();
	}

	/**
     * Runs the widget.
     */
	public function run()
    {
    	$id = $this->options['id'];
    	$options = Json::htmlEncode($this->getClientOptions());
    	$view = $this->getView();
    	TreeGridAsset::register($view);
    	$view->registerJs("jQuery('#$id > table').treegrid($options);");

        if ($this->dataProvider->getCount() > 0) {
            $content = $this->renderItems();
        } else {
            $content = $this->renderEmpty();
        }
        $options = $this->options;
        $tag = ArrayHelper::remove($options, 'tag', 'div');
        echo Html::tag($tag, $content, $options);
    }

    /**
     * Returns the options for the treegrid JS plugin.
     * @return array the options
     */
    protected function getClientOptions()
    {
    	$options = $this->pluginOptions;
		if (!isset($options['source'])) {
			$url = Url::toRoute('');
			$options['source'] = new JsExpression('function(id, complete) {
				var $tr = this, token = Math.random().toString(36).substr(2);
				console.log("start loading");
				$.get("'.$url.'", {treegrid_id: id, treegrid_token: token}, function(data) {
					var items = $(data).find(\'[data-treegrid-token="\'+token+\'"] > table > tbody > tr\');
					console.log("data readed: "+items.length);
					complete(items);
				});
			}');
		}

        return $options;
    }

	/**
     * @see yii\widgets\BaseListView::renderItems()
     */
    public function renderItems()
    {
        $columnGroup = $this->renderColumnGroup();
        $tableHeader = $this->showHeader ? $this->renderTableHeader() : false;
        $tableBody = $this->renderTableBody();
        $tableFooter = $this->showFooter ? $this->renderTableFooter() : false;
        $content = array_filter([
            $columnGroup,
            $tableHeader,
            $tableFooter,
            $tableBody,
        ]);
        return Html::tag('table', implode("\n", $content), $this->tableOptions);
    }

	/**
     * @see yii\widgets\BaseListView::renderEmpty()
     */
    public function renderEmpty()
    {
        $options = $this->emptyTextOptions;
        $tag = ArrayHelper::remove($options, 'tag', 'div');
        return Html::tag($tag, $this->emptyText, $options);
    }

    /**
     * @see yii\grid\GridView::renderColumnGroup()
     */
    public function renderColumnGroup()
    {
        $requireColumnGroup = false;
        foreach ($this->columns as $column) {
            /* @var $column Column */
            if (!empty($column->options)) {
                $requireColumnGroup = true;
                break;
            }
        }
        if ($requireColumnGroup) {
            $cols = [];
            foreach ($this->columns as $column) {
                $cols[] = Html::tag('col', '', $column->options);
            }
            return Html::tag('colgroup', implode("\n", $cols));
        } else {
            return false;
        }
    }

    /**
     * @see yii\grid\GridView::renderTableHeader()
     */
    public function renderTableHeader()
    {
        $cells = [];
        foreach ($this->columns as $column) {
            /* @var $column Column */
            $cells[] = $column->renderHeaderCell();
        }
        $content = Html::tag('tr', implode('', $cells), $this->headerRowOptions);
        return "<thead>\n" . $content . "\n</thead>";
    }

    /**
     * @see yii\grid\GridView::renderTableFooter()
     */
    public function renderTableFooter()
    {
        $cells = [];
        foreach ($this->columns as $column) {
            /* @var $column Column */
            $cells[] = $column->renderFooterCell();
        }
        $content = Html::tag('tr', implode('', $cells), $this->footerRowOptions);
        return "<tfoot>\n" . $content . "\n</tfoot>";
    }

    /**
     * @see yii\grid\GridView::renderTableBody()
     */
    public function renderTableBody()
    {
        $models = array_values($this->dataProvider->getModels());
        $keys = $this->dataProvider->getKeys();
        $rows = [];
        foreach ($models as $index => $model) {
            $key = $keys[$index];
            if ($this->beforeRow !== null) {
                $row = call_user_func($this->beforeRow, $model, $key, $index, $this);
                if (!empty($row)) {
                    $rows[] = $row;
                }
            }
            $rows[] = $this->renderTableRow($model, $key, $index);
            if ($this->afterRow !== null) {
                $row = call_user_func($this->afterRow, $model, $key, $index, $this);
                if (!empty($row)) {
                    $rows[] = $row;
                }
            }
        }
        return "<tbody>\n" . implode("\n", $rows) . "\n</tbody>";
    }

	/**
     * @see yii\grid\GridView::renderTableRow()
	 */
	public function renderTableRow($model, $key, $index) {
		$cells = [];
		/* @var $column Column */
		foreach ($this->columns as $column) {
			$cells[] = $column->renderDataCell($model, $key, $index);
		}
		if ($this->rowOptions instanceof Closure) {
			$options = call_user_func($this->rowOptions, $model, $key, $index, $this);
		} else {
			$options = $this->rowOptions;
		}
		$key = is_array($key) ? json_encode($key) : (string) $key;
		$options['data-key'] = $key;

		//treegrid
		Html::addCssClass($options, 'treegrid-'.$key);
		$parentId = $this->getParentId($model, $key, $index);
		if ($parentId !== null) {
			Html::addCssClass($options, 'treegrid-parent-'.$parentId);
		}
		$childCount = $this->getChildCount($model, $key, $index);
		if ($childCount) {
			$options['data-count'] = $childCount;
		}

		return Html::tag('tr', implode('', $cells), $options);
	}

	/**
	 * Additional params for data provider.
	 */
	protected function initDataProvider() {
		$this->dataProvider->pagination = false;
		$this->dataProvider->sort = false;
		if ($this->dataProvider instanceof ActiveDataProvider) {
			$id = Yii::$app->getRequest()->get('treegrid_id', null);
			if ($this->lazyLoad || $id !== null) {
				$this->addNodeCondition($id);
				$token = Yii::$app->getRequest()->get('treegrid_token', null);
				if ($token !== null) $this->options['data-treegrid-token'] = $token;
			}
		}
	}

	/**
     * @see yii\grid\GridView::initColumns()
     */
    protected function initColumns()
    {
        if (empty($this->columns)) {
            $this->guessColumns();
        }
        foreach ($this->columns as $i => $column) {
            if (is_string($column)) {
                $column = $this->createDataColumn($column);
            } else {
                $column = Yii::createObject(array_merge([
                    'class' => $this->dataColumnClass ? : DataColumn::className(),
                    'grid' => $this,
                ], $column));
            }
            if (!$column->visible) {
                unset($this->columns[$i]);
                continue;
            }
            $this->columns[$i] = $column;
        }
    }

    /**
     * @see yii\grid\GridView::createDataColumn()
     */
    protected function createDataColumn($text)
    {
        if (!preg_match('/^([^:]+)(:(\w*))?(:(.*))?$/', $text, $matches)) {
            throw new InvalidConfigException('The column must be specified in the format of "attribute", "attribute:format" or "attribute:format:label"');
        }
        return Yii::createObject([
            'class' => $this->dataColumnClass ? : DataColumn::className(),
            'grid' => $this,
            'attribute' => $matches[1],
            'format' => isset($matches[3]) ? $matches[3] : 'text',
            'label' => isset($matches[5]) ? $matches[5] : null,
        ]);
    }

    /**
     * @see yii\grid\GridView::guessColumns()
     */
    protected function guessColumns()
    {
        $models = $this->dataProvider->getModels();
        $model = reset($models);
        if (is_array($model) || is_object($model)) {
            foreach ($model as $name => $value) {
                $this->columns[] = (string) $name;
            }
        }
    }

	/**
	 * Returns parent id of model for row render.
	 * @param mixed $model the data model to be rendered
	 * @param mixed $key the key associated with the data model
	 * @param integer $index the zero-based index of the data model among the model array returned by [[dataProvider]].
	 * @return mixed parent id of model. If model does not have a parent node returns null.
	 */
	abstract protected function getParentId($model, $key, $index);

	/**
	 * Returns count of children of model.
	 * @param mixed $model the data model to be rendered
	 * @param mixed $key the key associated with the data model
	 * @param integer $index the zero-based index of the data model among the model array returned by [[dataProvider]].
	 * @return integer count of child of the model
	 */
	abstract protected function getChildCount($model, $key, $index);

	/**
	 * Addition conditions for child nodes filtering.
	 * @param string $id node id
	 * @return void
	 */
	abstract protected function addNodeCondition($id);

}
