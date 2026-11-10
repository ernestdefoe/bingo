import Extend from 'flarum/common/extenders';
import Discussion from 'flarum/common/models/Discussion';
import BingoPost from './components/BingoPost';
import { ResultNotification, ReviewNotification } from './notifications';

export default [
  new Extend.PostTypes().add('bingo', BingoPost),

  new Extend.Notification().add('bingoReview', ReviewNotification).add('bingoResult', ResultNotification),

  new Extend.Model(Discussion).attribute('bingoGames').attribute<boolean>('canStartBingo'),
];
