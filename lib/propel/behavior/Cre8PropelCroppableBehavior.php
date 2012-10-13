<?php

class Cre8PropelCroppableBehavior extends SfPropelBehaviorBase {

    // default parameters value
    protected $parameters = array(
        'images' => array('image')
    );

    public function modifyTable() {
        $this->parseOptions();

        foreach ($this->getParameter('images') as $imageField) {
            if (!$this->getTable()->containsColumn($imageField)) {
                $this->getTable()->addColumn(array(
                    'name' => $imageField,
                    'type' => 'VARCHAR',
                    'size' => 255
                ));
            }
            foreach (array('x1', 'y1', 'x2', 'y2') as $suffix) {
                $newColumnName = $imageField . '_' . $suffix;
                if (!$this->getTable()->containsColumn($newColumnName)) {
                    $this->getTable()->addColumn(array(
                        'name' => $newColumnName,
                        'type' => 'VARCHAR',
                        'size' => 255
                    ));
                }
            }
        }
    }

    protected function parseOptions() {
        if (is_string($this->getParameter('images'))) {
            $param = trim($this->getParameter('images'));
            $elements = explode('|', $param);

            $images = array();
            foreach ($elements as $element) {
                $images[] = $element;
            }

            if (count($images) > 0)
                $this->parameters['images'] = $images;
        }
    }

    public function preInsert() {
        return "\$this->checkImages();";
    }

    public function preUpdate() {
        return "\$this->checkImages();";
    }

    public function objectMethods($builder) {
        $this->builder = $builder;
        $script = '';

        $this->addVariables($script);

        $this->addGetImageFromName($script);
        $this->addLoadImage($script);
        $this->addGetImageSrc($script);
        $this->addGetImageTag($script);
        $this->addCreateEditableImage($script);
        $this->addCreateCrops($script);
        $this->addCreateCropForSize($script);
        $this->addRemoveImages($script);
        $this->addGetImageDir($script);
        $this->addGetImageDirDefault($script);
        $this->addGetImageDirWeb($script);
        $this->addGetImageConfig($script);
        $this->addAddPadding($script);
        $this->addConfigureJCropWidgets($script);
        $this->addConfigureJCropValidators($script);
        $this->addUpdateImage($script);
        $this->addCheckImages($script);
        $this->addCalculateResolution($script);

        return $script;
    }

    private function getTableNameCamelCase() {
        return $this->getTable()->getPhpName();
    }

    private function getTableName() {
        return $this->getTable()->getName();
    }

    protected function addVariables(&$script) {
        $parameters = var_export($this->getParameters(), true);
        $script .= "
    protected \$_options = {$parameters};
    private \$editableImages = array();
    private \$originalImages = array();
";
    }

    protected function addGetImageFromName(&$script) {
        $script .= "
  /**
   * Gets the filename for the given image field and size. Uses the current field value,
   *  but can be overriden by passing a different value as the 3rd parameter
   *
   * @param \$fieldName
   * @param \$size
   * @param \$editable
   * @return \$image
   */
  private function getImageFromName(\$fieldName, \$size = 'editable', \$editable = null) {
    if (!\$imageConfig = \$this->getImageConfig(\$fieldName)) {
      return false;
    }

    if (\$editable == null) {
      \$editable = \$this->\$fieldName;
    }

    if (\$size == 'editable' || (!isset(\$imageConfig['sizes'][\$size]) && \$size != 'original')) {
      return \$editable;
    }

    \$extensionPosition = strrpos(\$editable, '.');
    \$stub = substr(\$editable, 0, \$extensionPosition);

    \$image = str_replace(\$stub, \$stub . '_' . \$size, \$editable);

    return \$image;
  }
";
    }

    protected function addLoadImage(&$script) {
        $script .= "
  /**
   * Loads either the editable or original version of the given image field
   *
   * @param string \$fieldName
   * @param string \$version - editable or original
   * @param \$force - try to load the image even if there's no config for image
   */
  private function loadImage(\$fieldName, \$version, \$force = false) {
    \$imageConfig = \$this->getImageConfig(\$fieldName);

    if (!\$this->\$fieldName || (!\$imageConfig && !\$force)) {
      return;
    }

    \$this->{\$version . 'Images'}[\$fieldName] =
      new sfImage(\$this->getImageDir() . DIRECTORY_SEPARATOR . \$this->getImageFromName(\$fieldName, \$version));
  }
";
    }

    protected function addGetImageSrc(&$script) {
        $script .= "
  /**
   * Gets the given field's absolute editable image path, and warns if the directory
   *  doesn't exist or is not writable
   *
   * @param string \$fieldName
   * @return string
   */
  public function getImageSrc(\$fieldName, \$size = 'thumb') {
    \$fileDir = \$this->getImageDirWeb();

    if (!file_exists(\$fileDir)) {
      print(\"image upload directory <strong>\$fileDir</strong> doesn't exist\");
    }
    if (!is_writable(\$fileDir)) {
      print(\"image upload directory <strong>\$fileDir</strong> is not writable\");
    }

        \$fileSrc = null;
        \$imageFromName = \$this->getImageFromName(\$fieldName, \$size);
        if (\$imageFromName)
            \$fileSrc = '/' . \$fileDir . '/' . \$imageFromName;

    return \$fileSrc;
  }
";
    }

    protected function addGetImageTag(&$script) {
        $script .= "
  /**
   * Returns an img tag for the specified image field & size (default thumb)
   *
   * @param string \$fieldName
   * @param string \$size
   * @param array \$attributes
   * @return string
   */
  public function getImageTag(\$fieldName, \$size = 'thumb', \$attributes = array())
  {
    \$imageSrc = \$this->getImageSrc(\$fieldName, \$size);
    return is_null(\$imageSrc)? null: tag(
      'img',
      array_merge(
        \$attributes,
        array('src' => \$imageSrc)
      )
    );
  }
";
    }

    protected function addCreateEditableImage(&$script) {
        $script .= "
  /**
   * Takes the original image, adds and padding to it and creates an editable version
   *  for use in the cropper
   *
   * @param string \$fieldName
   */
  private function createEditableImage(\$fieldName) {
    \$imageConfig = \$this->getImageConfig(\$fieldName);
    /**
     * Get the filenames for the editoable and original versions of the image
     */
    \$original = \$this->getImageFromName(\$fieldName, 'original');
    \$editable = \$this->getImageFromName(\$fieldName, 'editable');

    if (empty(\$original) || empty(\$editable))
    {
      return false;
    }

    \$dir = \$this->getImageDir();

    /**
     * Move the new image to be named as the original
     */
    rename(\$dir . DIRECTORY_SEPARATOR . \$editable, \$dir . DIRECTORY_SEPARATOR . \$original);
    //print(\"mv \$editable \$original<br/>\");exit;
    /**
     * Load the original and resize it for the editable version
     */
    \$img = new sfImage(\$dir . DIRECTORY_SEPARATOR . \$original);

    if (isset(\$imageConfig['padding'])) {
      \$img = \$this->addPadding(\$img, \$imageConfig['padding']);

      \$img->saveAs(\$dir . DIRECTORY_SEPARATOR . \$original);
    }

    \$res = \$this->calculateResolution(\$img->getWidth(), \$img->getHeight(), \$imageConfig['editable']['width'], \$imageConfig['editable']['height']);
    \$img->resize(\$res['width'], \$res['height']);
    \$img->saveAs(\$dir . DIRECTORY_SEPARATOR . \$editable);

    \$this->{\$fieldName . '_x1'} = 0;
    \$this->{\$fieldName . '_y1'} = 0;
    \$this->{\$fieldName . '_x2'} = \$img->getWidth();
    \$this->{\$fieldName . '_y2'} = \$img->getHeight();
  }
";
    }

    protected function addCreateCrops(&$script) {
        $script .= "
  /**
   * Creates the cropped version of the given field's images
   *
   * @param string \$fieldName
   * @return bool
   */
  private function createCrops(\$fieldName) {
    if (!\$imageConfig = \$this->getImageConfig(\$fieldName)) {
      return false;
    }

    \$this->loadImage(\$fieldName, 'editable');
    \$this->loadImage(\$fieldName, 'original');

    foreach (\$imageConfig['sizes'] as \$size => \$dims) {

      \$this->createCropForSize(\$fieldName, \$size);

    }

    return true;
  }
";
    }

    protected function addCreateCropForSize(&$script) {
        $script .= "
  /**
   * Creates the crop of the given field's image at the specified size
   *
   * @param \$fieldName
   * @param \$size
   */
  private function createCropForSize(\$fieldName, \$size) {
    if (!\$imageConfig = \$this->getImageConfig(\$fieldName)) {
      return false;
    }

    \$this->loadImage(\$fieldName, 'original');
    \$this->loadImage(\$fieldName, 'editable');

    if (empty(\$this->originalImages[\$fieldName]) || empty(\$this->editableImages[\$fieldName]))
    {
      return false;
    }

    if(!empty(\$imageConfig['ratio']))
        \$ratio = \$imageConfig['ratio'];
    else
    \$ratio = \$this->originalImages[\$fieldName]->getWidth() /
      \$this->editableImages[\$fieldName]->getWidth();

    \$dims['x'] = (int)\$this->{\$fieldName . '_x1'} * \$ratio;
    \$dims['y'] = (int)\$this->{\$fieldName . '_y1'} * \$ratio;
    \$dims['w'] = (int)(\$this->{\$fieldName . '_x2'} * \$ratio) - \$dims['x'];
    \$dims['h'] = (int)(\$this->{\$fieldName . '_y2'} * \$ratio) - \$dims['y'];

    \$origCrop = \$this->originalImages[\$fieldName]
      ->crop(\$dims['x'], \$dims['y'], \$dims['w'], \$dims['h']);

    \$res = \$this->calculateResolution(\$origCrop->getWidth(), \$origCrop->getHeight(), \$imageConfig['sizes'][\$size]['width'], \$imageConfig['sizes'][\$size]['width'] / \$ratio);
    \$finalCrop = \$origCrop->resize(\$res['width'], \$res['height']);

    \$fullPath = \$this->getImageDir() . DIRECTORY_SEPARATOR . \$this->getImageFromName(\$fieldName, \$size);

    \$finalCrop->saveAs(\$fullPath);
  }
";
    }

    protected function addRemoveImages(&$script) {
        $script .= "
  /**
   * Removes all existing images for the given field, and the field's value
   *  can be overridden using the second parameter
   *
   * @param \$fieldName
   * @param \$editable
   */
  private function removeImages(\$fieldName, \$editable) {
    if (!\$imageConfig = \$this->getImageConfig(\$fieldName)) {
      return;
    }

    /**
     * Remove the editable & original images
     */
    foreach (array('editable', 'original') as \$type) {
      \$fullPath = \$this->getImageDir() . DIRECTORY_SEPARATOR
        . \$this->getImageFromName(\$fieldName, \$type, \$editable);

      if (file_exists(\$fullPath)) {
        unlink(\$fullPath);
      }
    }

    /**
     * Loop through the sizes and remove them
     */
    foreach (\$imageConfig['sizes'] as \$size => \$dims) {

      \$filename = \$this->getImageFromName(\$fieldName, \$size, \$editable);

      \$fullPath = \$this->getImageDir() . DIRECTORY_SEPARATOR . \$filename;

      if (file_exists(\$fullPath)) {
        unlink(\$fullPath);
      }

    }
  }
";
    }

    protected function addGetImageDir(&$script) {
        $script .= "
    /**
     * Get the directory to store the images in by looking in the following places:
     *
     *  1) table specific config
     *  2) global plugin config
     *  3) default location
     *
     * @return string
     */
    private function getImageDir() {
        \$config = sfConfig::get('app_cre8PropelActAsCroppableBehaviorPlugin_models');

        \$basePath = sfConfig::get('sf_upload_dir');

        \$tableName = '{$this->getTableNameCamelCase()}';

        if (!empty(\$config[\$tableName]['directory'])) {

            \$relativePath = \$config[\$tableName]['directory'];
        } else {

            \$relativePath = \$this->getImageDirDefault();
        }

        return \$basePath . DIRECTORY_SEPARATOR . \$relativePath;
    }
";
    }

    protected function addGetImageDirDefault(&$script) {
        $script .= "
    /**
     * Generate's the default directory to store the model's images in (relative to uploads/)
     *
     * @return string
     */
    private function getImageDirDefault() {
        return 'images' . DIRECTORY_SEPARATOR . '{$this->getTableNameCamelCase()}';
    }
";
    }

    protected function addGetImageDirWeb(&$script) {
        $script .= "
    /**
     * Gets the model's image directory relative to the web root (sf_web_dir)
     *
     * @return string
     */
    private function getImageDirWeb() {
        \$webDir = str_replace('\\\', '/', sfConfig::get('sf_web_dir'));
        \$imageDir = str_replace('\\\', '/', \$this->getImageDir());

        return (string) str_replace(\$webDir . '/', '', \$imageDir);
    }
";
    }

    protected function addGetImageConfig(&$script) {
        $script .= "
    /**
     * Get's the config for the given field's image
     *
     * @param \$fieldName
     * @return array
     */
    private function getImageConfig(\$fieldName) {
        \$config = sfConfig::get('app_cre8PropelActAsCroppableBehaviorPlugin_models');

        if (!isset(\$config['{$this->getTableNameCamelCase()}']['images'][\$fieldName])) {
            return array('sizes' => array(
                    'thumb' => array('width' => 120),
                    'main' => array('width' => 360)),
                    'editable' => array('width' => 600, 'height' => 700)
            );
        }

        return \$config['{$this->getTableNameCamelCase()}']['images'][\$fieldName];
    }
";
    }

    protected function addAddPadding(&$script) {
        $script .= "
      /**
     * Adds any padding to the given image using the supplied padding config
     *
     * @param \$img
     * @param array \$padding
     * @return \$img
     */
    private function addPadding(\$img, \$padding) {
        if (!\$padding) {
            return \$img;
        }

        if (isset(\$padding['percent']) && is_numeric(\$padding['percent'])) {

            \$width = \$img->getWidth() * (1 + (\$padding['percent'] / 100));
            \$height = \$img->getHeight() * (1 + (\$padding['percent'] / 100));
        } else if (isset(\$padding['pixels']) && is_numeric(\$padding['pixels'])) {

            \$width = \$img->getWidth() + \$padding['pixels'];
            \$height = \$img->getHeight() + \$padding['pixels'];
        } else {

            return \$img;
        }

        \$canvas = new sfImage();
        \$canvas
                ->fill(0, 0, isset(\$padding['color']) ? \$padding['color'] : '#ffffff')
                ->resize(\$width, \$height)
                ->overlay(\$img, 'center');

        return \$canvas;
    }
";
    }

    protected function addUpdateImage(&$script) {
        $script .= "
    /**
     * Performs the following operations for a given image:
     *  1) removes any old files if the image has been re-uploaded
     *  2) creates a scaled down version for editing in the cropper if the image has been (re)uploaded
     *  3) creates the cropped versions of the image
     *
     * This method is called from the listener if the image has been edited in any way
     *
     * @param string \$fieldName
     */
    public function updateImage(\$fieldName) {
        \$this->getPeer()->removeInstanceFromPool(\$this);
        \$oldObject = \$this->getPeer()->retrieveByPK(\$this->getId());
        \$imageFieldMethod = 'get' . BookPeer::translateFieldName(\$fieldName, BasePeer::TYPE_FIELDNAME, BasePeer::TYPE_PHPNAME);
        \$file = (\$oldObject) ? call_user_func(array(\$oldObject, \$imageFieldMethod)) : null;

        \$imageField = BookPeer::translateFieldName(\$fieldName, BasePeer::TYPE_FIELDNAME, BasePeer::TYPE_COLNAME);

        if (\$this->isColumnModified(\$imageField)) {

            if (!empty(\$file) && \$file != call_user_func(array(\$this, \$imageFieldMethod)))
                \$this->removeImages(\$fieldName, \$file);

            \$this->createEditableImage(\$fieldName);
        }

        \$this->createCrops(\$fieldName);
    }
";
    }

    protected function addConfigureJCropWidgets(&$script) {
        $script .= "
    /**
   * Takes a form and configures each image's widget.
   *
   * This is one of only 2 methods the user needs to call manually (the other being configureJCropValidators)
   * Should be called from the form's configure() method
   *
   * @param sfForm \$form
   */
  public function configureJCropWidgets(sfForm \$form, \$formOptions = array()) {

    foreach (\$this->_options['images'] as \$fieldName) {
      if (!\$imageConfig = \$this->getImageConfig(\$fieldName))
      {
        continue;
      }

      \$fileSrc = \$this->getImageSrc(\$fieldName, 'editable');
      \$form->setWidget(\$fieldName,
        new cre8WidgetFormInputFileInputImageJCroppable(array(
          'invoker' => \$form->getObject()->isNew(),
          'image_field' => \$fieldName,
          'image_ratio' => isset(\$imageConfig['ratio']) ? \$imageConfig['ratio'] : false,
          'with_delete' => is_null(\$fileSrc)? false : true,
          'file_src' => is_null(\$fileSrc)? false : \$fileSrc,
          'template'  => '%file%<br />%input%<br />%delete% %delete_label%',
          'form' => \$form
        ))
      );

      foreach (array('x1', 'y1', 'x2', 'y2') as \$suffix) {
        \$form->setWidget(\$fieldName . '_' . \$suffix, new sfWidgetFormInputHidden());
      }
    }
  }
";
    }

    protected function addConfigureJCropValidators(&$script) {
        $script .= "
                /**
   * Takes a form and configures each image's widget.
   *
   * This is one of only 2 methods the user needs to call manually (the other being configureJCropWidgets)
   * Should be called from the form's configure() method
   *
   * @param sfForm \$form
   */
  public function configureJCropValidators(\$form) {

    foreach (\$this->_options['images'] as \$fieldName) {

      \$form->setValidator(\$fieldName . '_delete',  new sfValidatorPass());

      \$form->setValidator(\$fieldName,
        new sfValidatorFile(array(
            'required'   => false,
            'path'       => \$this->getImageDir(),
            'mime_types' => 'web_images',
          ),
          array('mime_types' => 'Unsupported image type (%mime_type%)')
        )
      );

        foreach (array('x1', 'y1', 'x2', 'y2') as \$suffix) {
            \$form->setValidator(\$fieldName . '_' . \$suffix, new sfValidatorPass());
        }
    }
  }
";
    }

    protected function addCheckImages(&$script) {
        $script .= "
    private function checkImages() {
        \$imageFieldSuffixes = array('', '_x1', '_y1', '_x2', '_y2');

        foreach (\$this->_options['images'] as \$imageName) {

            \$needsUpdate = false;

            foreach (\$imageFieldSuffixes as \$suff) {
                \$fieldName = {$this->getTableNameCamelCase()}Peer::translateFieldName(\$imageName . \$suff, BasePeer::TYPE_FIELDNAME, BasePeer::TYPE_COLNAME);

                if (\$this->isColumnModified(\$fieldName)) {
                    \$needsUpdate = true;
                    break;
                }
            }

            if (\$needsUpdate) {
                \$this->updateImage(\$imageName);
            }
        }
    }  
";
    }

    protected function addCalculateResolution(&$script) {
        $script .= "
   /* Resolution calculator
   *
   * @param int current width of image
   * @param int current height of image
   * @param int max width of image
   * @param int max height of image
   * @param boolean (optional) if true image scales
   * @param boolean (optional) if true inflate small images
   */
  public function calculateResolution(\$sourceWidth, \$sourceHeight, \$maxWidth, \$maxHeight, \$scale = true, \$inflate = true)
  {
    \$ratioWidth;
    \$ratioHeight;
      if (\$maxWidth > 0)
    {
      \$ratioWidth = \$maxWidth / \$sourceWidth;
    }
    if (\$maxHeight > 0)
    {
      \$ratioHeight = \$maxHeight / \$sourceHeight;
    }

          \$thumbWidth;
      \$thumbHeight;
    if (\$scale)
    {
      if (\$maxWidth && \$maxHeight)
      {
        \$ratio = (\$ratioWidth < \$ratioHeight) ? \$ratioWidth : \$ratioHeight;
      }
      if (\$maxWidth xor \$maxHeight)
      {
        \$ratio = (isset(\$ratioWidth)) ? \$ratioWidth : \$ratioHeight;
      }
      if ((!\$maxWidth && !\$maxHeight) || (!\$inflate && \$ratio > 1))
      {
        \$ratio = 1;
      }

      \$thumbWidth = floor(\$ratio * \$sourceWidth);
      \$thumbHeight = ceil(\$ratio * \$sourceHeight);
    }
    else
    {
      if (!isset(\$ratioWidth) || (!\$inflate && \$ratioWidth > 1))
      {
        \$ratioWidth = 1;
      }
      if (!isset(\$ratioHeight) || (!\$inflate && \$ratioHeight > 1))
      {
        \$ratioHeight = 1;
      }
      \$thumbWidth = floor(\$ratioWidth * \$sourceWidth);
      \$thumbHeight = ceil(\$ratioHeight * \$sourceHeight);
    }
    return array('width' => \$thumbWidth, 'height' => \$thumbHeight);
  }
";
    }

}