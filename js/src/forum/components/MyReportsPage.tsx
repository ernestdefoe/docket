import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Placeholder from 'flarum/common/components/Placeholder';
import humanTime from 'flarum/common/helpers/humanTime';
import { truncate } from 'flarum/common/utils/string';
import type Mithril from 'mithril';
import type { Entry } from '../../common/models';
import { statusBadge, reasonLabel } from '../labels';
import { t } from '../t';

/**
 * The posts you reported and how each report went, as far as staff chose to
 * tell you.
 */
export default class MyReportsPage extends Page {
  entries: Entry[] = [];
  loading = true;
  more = false;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);

    if (!app.session.user) {
      m.route.set(app.route('index'));
      return;
    }

    app.setTitle(t('mine.title'));
    this.load(0);
  }

  load(offset: number) {
    this.loading = true;

    app.store
      .find<Entry[]>('docket-entries', { page: { offset, limit: 20 } })
      .then((entries) => {
        this.entries.push(...entries);
        this.more = !!(entries as any).payload?.links?.next;
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    return (
      <div className="DocketPage DocketMinePage">
        <div className="container">
          <h2 className="DocketPage-title">{t('mine.title')}</h2>
          <p className="DocketPage-intro">{t('mine.intro')}</p>
          {!this.loading && !this.entries.length ? <Placeholder text={t('mine.empty')} /> : null}
          <ul className="DocketMine">
            {this.entries.map((entry) => {
              const post = entry.post();
              const discussion = post ? post.discussion() : null;

              return (
                <li className="DocketCard DocketMine-item" key={entry.id()}>
                  <div className="DocketMine-head">
                    {statusBadge(entry.reportStatus())}
                    <span className="DocketMine-when">{humanTime(entry.createdAt()!)}</span>
                  </div>
                  <div className="DocketMine-what">
                    {post && discussion ? (
                      <Link href={app.route.post(post)}>{discussion.title()}</Link>
                    ) : (
                      <span className="DocketMine-gone">{t('mine.post_unavailable')}</span>
                    )}
                    {post ? <div className="DocketMine-excerpt">{truncate(post.contentPlain() || '', 140)}</div> : null}
                  </div>
                  <div className="DocketMine-reason">
                    {t('mine.your_reason')} {reasonLabel(entry)}
                    {entry.reasonDetail() ? <span className="DocketMine-detail"> — {entry.reasonDetail()}</span> : null}
                  </div>
                  {entry.outcome() ? <blockquote className="DocketMine-outcome">{entry.outcome()}</blockquote> : null}
                </li>
              );
            })}
          </ul>
          {this.loading ? <LoadingIndicator /> : null}
          {this.more && !this.loading ? (
            <div className="DocketList-more">
              <Button className="Button" onclick={() => this.load(this.entries.length)}>
                {t('list.load_more')}
              </Button>
            </div>
          ) : null}
        </div>
      </div>
    );
  }
}
