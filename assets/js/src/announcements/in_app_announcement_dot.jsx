import PropTypes from 'prop-types';
import React from 'react';
import ReactDOMServer from 'react-dom/server';
import classNames from 'classnames';
import MailPoet from 'mailpoet';

const InAppAnnouncementDot = (props) => {
  const onClick = () => {
    MailPoet.Modal.popup({
      template: ReactDOMServer.renderToString(props.children),
      width: props.width,
      height: props.height,
    });
    if (props.onUserTrigger) props.onUserTrigger();
  };
  return (
    <span
      role="button"
      tabIndex="-1"
      className={classNames('mailpoet_in_app_announcement_pulsing_dot', props.className)}
      onKeyDown={(event) => {
        if ((['keydown', 'keypress'].includes(event.type) && ['Enter', ' '].includes(event.key))
        ) {
          event.preventDefault();
          onClick();
        }
      }}
      onClick={onClick}
    />
  );
};

InAppAnnouncementDot.propTypes = {
  children: PropTypes.element.isRequired,
  width: PropTypes.string,
  height: PropTypes.string,
  className: PropTypes.string,
  onUserTrigger: PropTypes.func,
};

InAppAnnouncementDot.defaultProps = {
  width: 'auto',
  height: 'auto',
  className: null,
  onUserTrigger: null,
};

export default InAppAnnouncementDot;
