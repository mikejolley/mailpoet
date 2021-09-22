import React from 'react';
import PropTypes from 'prop-types';
import MailPoet from 'mailpoet';
import classNames from 'classnames';
import { Link, withRouter } from 'react-router-dom';
import ReactStringReplace from 'react-string-replace';

import Toggle from 'common/form/toggle/toggle';
import Tags from 'common/tag/tags';
import { ScheduledIcon } from 'common/listings/newsletter_status';
import Listing from 'listing/listing.jsx';
import Statistics from 'newsletters/listings/statistics.jsx';
import {
  addStatsCTAAction,
  checkCronStatus,
  checkMailerStatus,
} from 'newsletters/listings/utils.jsx';
import NewsletterTypes from 'newsletters/types';

const mailpoetTrackingEnabled = (!!(window.mailpoet_tracking_enabled));

const messages = {
  onNoItemsFound: (group, search) => MailPoet.I18n.t(search ? 'noItemsFound' : 'emptyListing'),
  onTrash: (response) => {
    const count = Number(response.meta.count);
    let message = null;

    if (count === 1) {
      message = (
        MailPoet.I18n.t('oneNewsletterTrashed')
      );
    } else {
      message = (
        MailPoet.I18n.t('multipleNewslettersTrashed')
      ).replace('%1$d', count.toLocaleString());
    }
    MailPoet.Notice.success(message);
  },
  onDelete: (response) => {
    const count = Number(response.meta.count);
    let message = null;

    if (count === 1) {
      message = (
        MailPoet.I18n.t('oneNewsletterDeleted')
      );
    } else {
      message = (
        MailPoet.I18n.t('multipleNewslettersDeleted')
      ).replace('%1$d', count.toLocaleString());
    }
    MailPoet.Notice.success(message);
  },
  onRestore: (response) => {
    const count = Number(response.meta.count);
    let message = null;

    if (count === 1) {
      message = (
        MailPoet.I18n.t('oneNewsletterRestored')
      );
    } else {
      message = (
        MailPoet.I18n.t('multipleNewslettersRestored')
      ).replace('%1$d', count.toLocaleString());
    }
    MailPoet.Notice.success(message);
  },
};

const columns = [
  {
    name: 'subject',
    label: MailPoet.I18n.t('subject'),
    sortable: true,
  },
  {
    name: 'settings',
    label: MailPoet.I18n.t('settings'),
  },
  {
    name: 'statistics',
    label: MailPoet.I18n.t('statistics'),
    display: mailpoetTrackingEnabled,
  },
  {
    name: 'status',
    label: MailPoet.I18n.t('status'),
    width: 145,
  },
  {
    name: 'updated_at',
    label: MailPoet.I18n.t('lastModifiedOn'),
    sortable: true,
  },
];

const bulkActions = [
  {
    name: 'trash',
    label: MailPoet.I18n.t('moveToTrash'),
    onSuccess: messages.onTrash,
  },
];

let newsletterActions = [
  {
    name: 'view',
    link: function link(newsletter) {
      return (
        <a href={newsletter.preview_url} target="_blank" rel="noopener noreferrer">
          {MailPoet.I18n.t('preview')}
        </a>
      );
    },
  },
  {
    name: 'duplicate',
    className: 'mailpoet-hide-on-mobile',
    label: MailPoet.I18n.t('duplicate'),
    onClick: (newsletter, refresh) => MailPoet.Ajax.post({
      api_version: window.mailpoet_api_version,
      endpoint: 'newsletters',
      action: 'duplicate',
      data: {
        id: newsletter.id,
      },
    }).done((response) => {
      MailPoet.Notice.success((MailPoet.I18n.t('newsletterDuplicated')).replace('%1$s', response.data.subject));
      refresh();
    }).fail((response) => {
      if (response.errors.length > 0) {
        MailPoet.Notice.error(
          response.errors.map((error) => error.message),
          { scroll: true }
        );
      }
    }),
  },
  {
    name: 'edit',
    className: 'mailpoet-hide-on-mobile',
    link: function link(newsletter) {
      return (
        <a href={`?page=mailpoet-newsletter-editor&id=${newsletter.id}`}>
          {MailPoet.I18n.t('edit')}
        </a>
      );
    },
  },
  {
    name: 'trash',
    className: 'mailpoet-hide-on-mobile',
  },
];
newsletterActions = addStatsCTAAction(newsletterActions);

class NewsletterListReEngagement extends React.Component {
  constructor(props) {
    super(props);
    this.state = {
      newslettersCount: undefined,
    };
  }

  updateStatus = (checked, e) => {
    // make the event persist so that we can still override the selected value
    // in the ajax callback
    e.persist();

    MailPoet.Ajax.post({
      api_version: window.mailpoet_api_version,
      endpoint: 'newsletters',
      action: 'setStatus',
      data: {
        id: Number(e.target.getAttribute('data-id')),
        status: checked ? 'active' : 'draft',
      },
    }).done((response) => {
      if (response.data.status === 'active') {
        MailPoet.Notice.success(MailPoet.I18n.t('reEngagementEmailActivated'));
      }
      // force refresh of listing so that groups are updated
      this.forceUpdate();
    }).fail((response) => {
      MailPoet.Notice.showApiErrorNotice(response);

      // reset value to previous newsletter's status
      e.target.checked = !checked;
    });
  };

  renderStatus = (newsletter) => {
    const totalSentMessage = MailPoet.I18n.t('sentToXSubscribers')
      .replace('%1$d', newsletter.total_sent.toLocaleString());

    return (
      <div>
        <Toggle
          className="mailpoet-listing-status-toggle"
          onCheck={this.updateStatus}
          data-id={newsletter.id}
          dimension="small"
          defaultChecked={newsletter.status === 'active'}
        />
        <p className="mailpoet-listing-stats-description">
          <Link
            to={`/sending-status/${newsletter.id}`}
            data-automation-id={`sending_status_${newsletter.id}`}
          >
            { totalSentMessage }
          </Link>
        </p>
      </div>
    );
  };

  renderSettings = (newsletter) => {
    if (newsletter.segments.length === 0) {
      return (
        <Link className="mailpoet-listing-error" to={`/send/${newsletter.id}`}>
          { MailPoet.I18n.t('sendingToSegmentsNotSpecified') }
        </Link>
      );
    }
    const sendingToSegments = ReactStringReplace(
      MailPoet.I18n.t('sendTo'),
      '%1$s',
      (match, i) => (
        <Tags segments={newsletter.segments} key={i} />
      )
    );

    let frequencyKey = 'reEngagementFrequencyMonth';
    if ((newsletter.options.afterTimeNumber > 1) && (newsletter.options.afterTimeType === 'months')) {
      frequencyKey = 'reEngagementFrequencyMonths';
    } else if ((newsletter.options.afterTimeNumber > 1) && (newsletter.options.afterTimeType === 'weeks')) {
      frequencyKey = 'reEngagementFrequencyWeeks';
    } else if ((newsletter.options.afterTimeNumber === 1) && (newsletter.options.afterTimeType === 'weeks')) {
      frequencyKey = 'reEngagementFrequencyWeek';
    }

    const sendingFrequency = MailPoet.I18n.t('reEngagementSettings')
      .replace('{$count}', newsletter.options.afterTimeNumber)
      .replace('{$frequency}', MailPoet.I18n.t(frequencyKey));

    return (
      <span>
        { sendingToSegments }
        <div className="mailpoet-listing-schedule">
          <div className="mailpoet-listing-schedule-icon">
            <ScheduledIcon />
          </div>
          { sendingFrequency }
        </div>
      </span>
    );
  }

  renderItem = (newsletter, actions) => {
    const rowClasses = classNames(
      'manage-column',
      'column-primary',
      'has-row-actions'
    );

    return (
      <div>
        <td className={rowClasses}>
          <a
            className="mailpoet-listing-title"
            href={`?page=mailpoet-newsletter-editor&id=${newsletter.id}`}
          >
            { newsletter.subject }
          </a>
          { actions }
        </td>
        <td className="column mailpoet-hide-on-mobile" data-colname={MailPoet.I18n.t('settings')}>
          { this.renderSettings(newsletter) }
        </td>
        { (mailpoetTrackingEnabled === true) ? (
          <td className="column mailpoet-listing-stats-column" data-colname={MailPoet.I18n.t('statistics')}>
            <Statistics
              newsletter={newsletter}
              isSent={newsletter.total_sent > 0 && !!newsletter.statistics}
            />
          </td>
        ) : null }
        <td className="column" data-colname={MailPoet.I18n.t('status')}>
          { this.renderStatus(newsletter) }
        </td>
        <td className="column-date mailpoet-hide-on-mobile" data-colname={MailPoet.I18n.t('lastModifiedOn')}>
          { MailPoet.Date.short(newsletter.updated_at) }
          <br />
          { MailPoet.Date.time(newsletter.updated_at) }
        </td>
      </div>
    );
  };

  isItemInactive = (newsletter) => newsletter.status === 'draft';

  render() {
    return (
      <>
        {this.state.newslettersCount === 0 && (
          <NewsletterTypes
            filter={(type) => type.slug === 're_engagement'}
            hideScreenOptions={false}
            hideClosingButton
          />
        )}
        {this.state.newslettersCount !== 0 && (
          <Listing
            limit={window.mailpoet_listing_per_page}
            location={this.props.location}
            params={this.props.match.params}
            endpoint="newsletters"
            type="re_engagement"
            base_url="re_engagement"
            onRenderItem={this.renderItem}
            isItemInactive={this.isItemInactive}
            columns={columns}
            bulk_actions={bulkActions}
            item_actions={newsletterActions}
            messages={messages}
            auto_refresh
            sort_by="updated_at"
            sort_order="desc"
            afterGetItems={(state) => {
              if (!state.loading) {
                const total = state.groups.reduce((count, group) => (count + group.count), 0);
                this.setState({ newslettersCount: total });
              }
              checkMailerStatus(state);
              checkCronStatus(state);
            }}
          />
        )}
      </>
    );
  }
}

NewsletterListReEngagement.propTypes = {
  location: PropTypes.object.isRequired, // eslint-disable-line react/forbid-prop-types
  match: PropTypes.shape({
    params: PropTypes.object, // eslint-disable-line react/forbid-prop-types
  }).isRequired,
};

export default withRouter(NewsletterListReEngagement);