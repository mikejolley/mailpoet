/* eslint-disable react/no-danger */
import React from 'react';
import {
  Panel,
  PanelBody,
  TextControl,
  ToggleControl,
} from '@wordpress/components';
import { InspectorControls } from '@wordpress/block-editor';
import PropTypes from 'prop-types';
import MailPoet from 'mailpoet';
import { useDispatch, useSelect } from '@wordpress/data';

import ParagraphEdit from '../paragraph_edit.jsx';
import CustomFieldSettings from './custom_field_settings.jsx';
import mapCustomFieldFormData from '../map_custom_field_form_data.jsx';

const CustomCheckboxEdit = ({ attributes, setAttributes, clientId }) => {
  const isSaving = useSelect(
    (sel) => sel('mailpoet-form-editor').getIsCustomFieldSaving(),
    []
  );
  const isDeleting = useSelect(
    (sel) => sel('mailpoet-form-editor').getIsCustomFieldDeleting(),
    []
  );
  const {
    saveCustomField,
    deleteCustomField,
    customFieldEdited,
  } = useDispatch('mailpoet-form-editor');

  const getCheckboxLabel = () => {
    if (Array.isArray(attributes.values)) {
      const value = attributes.values[0];
      if (value) {
        return value.name;
      }
    }
    return '';
  };

  const isChecked = () => {
    let checked = false;
    if (Array.isArray(attributes.values)) {
      const value = attributes.values[0];
      if (value && value.isChecked) {
        checked = true;
      }
    }
    return checked;
  };

  const inspectorControls = (
    <InspectorControls>
      <Panel>
        <PanelBody title={MailPoet.I18n.t('customFieldSettings')} initialOpen>
          <CustomFieldSettings
            mandatory={attributes.mandatory}
            isSaving={isSaving}
            isChecked={isChecked()}
            checkboxLabel={getCheckboxLabel()}
            onSave={(params) => saveCustomField({
              customFieldId: attributes.customFieldId,
              data: {
                params: mapCustomFieldFormData('checkbox', params),
              },
              onFinish: () => setAttributes({
                mandatory: params.mandatory,
                values: [{
                  isChecked: params.isChecked,
                  name: params.checkboxLabel,
                }],
              }),
            })}
            onCustomFieldDelete={() => deleteCustomField(
              attributes.customFieldId,
              clientId
            )}
            isDeleting={isDeleting}
            onChange={(data, hasUnsavedChanges) => hasUnsavedChanges && customFieldEdited()}
          />
        </PanelBody>
      </Panel>
      <Panel>
        <PanelBody title={MailPoet.I18n.t('formSettings')} initialOpen>
          <TextControl
            label={MailPoet.I18n.t('label')}
            value={attributes.label}
            data-automation-id="settings_custom_text_label_input"
            onChange={(label) => (setAttributes({ label }))}
          />
          <ToggleControl
            label={MailPoet.I18n.t('displayLabel')}
            checked={!attributes.hideLabel}
            onChange={(hideLabel) => (setAttributes({ hideLabel: !hideLabel }))}
          />
        </PanelBody>
      </Panel>
    </InspectorControls>
  );

  const getLabel = () => {
    if (attributes.hideLabel || !attributes.label) return null;
    return attributes.label;
  };

  let checkboxLabel = getCheckboxLabel();
  if (attributes.mandatory) {
    checkboxLabel += ' *';
  }

  return (
    <ParagraphEdit className={attributes.className}>
      {inspectorControls}
      <span className="mailpoet_checkbox_label" data-automation-id="editor_custom_field_checkbox_block">{getLabel()}</span>
      <div>
        <label>
          <input
            type="checkbox"
            disabled
            checked={isChecked()}
            className="mailpoet_checkbox"
          />
          <span dangerouslySetInnerHTML={{ __html: checkboxLabel }} />
        </label>
      </div>
    </ParagraphEdit>
  );
};

CustomCheckboxEdit.propTypes = {
  attributes: PropTypes.shape({
    label: PropTypes.string.isRequired,
    customFieldId: PropTypes.number.isRequired,
    mandatory: PropTypes.bool.isRequired,
    hideLabel: PropTypes.bool,
    className: PropTypes.string,
    values: PropTypes.arrayOf(PropTypes.shape({
      name: PropTypes.string.isRequired,
      isChecked: PropTypes.bool,
    })),
  }).isRequired,
  setAttributes: PropTypes.func.isRequired,
  clientId: PropTypes.string.isRequired,
};

export default CustomCheckboxEdit;
