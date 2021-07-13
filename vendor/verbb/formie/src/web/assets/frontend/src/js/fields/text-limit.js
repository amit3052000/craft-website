import { eventKey } from '../utils/utils';

export class FormieTextLimit {
    constructor(settings = {}) {
        this.$form = settings.$form;
        this.form = this.$form.form;
        this.$field = settings.$field;
        this.$text = this.$field.querySelector('[data-max-limit]');

        if (this.$text) {
            this.initTextMax();
        } else {
            console.error('Unable to find rich text field “[data-max-limit]”');
        }
    }

    initTextMax() {
        this.maxChars = this.$text.getAttribute('data-max-chars');
        this.maxWords = this.$text.getAttribute('data-max-words');

        if (this.maxChars) {
            this.form.addEventListener(this.$field, eventKey('keydown'), this.characterCheck.bind(this), false);
        }

        if (this.maxWords) {
            this.form.addEventListener(this.$field, eventKey('keydown'), this.wordCheck.bind(this), false);
        }
    }

    characterCheck(e) {
        setTimeout(() => {
            // If we're using a rich text editor, treat it a little differently
            var isRichText = e.target.hasAttribute('contenteditable');

            var value = isRichText ? e.target.innerHTML : e.target.value;

            var charactersLeft = this.maxChars - value.length;

            if (charactersLeft <= 0) {
                charactersLeft = '0';
            }

            this.$text.innerHTML = t('{num} characters left', {
                num: charactersLeft,
            });
        }, 1);
    }

    wordCheck(e) {
        setTimeout(() => {
            // If we're using a rich text editor, treat it a little differently
            var isRichText = e.target.hasAttribute('contenteditable');

            var value = isRichText ? e.target.innerHTML : e.target.value;
            
            var wordCount = value.split(/\S+/).length - 1;
            var regex = new RegExp('^\\s*\\S+(?:\\s+\\S+){0,' + (this.maxWords - 1) + '}');
            
            if (wordCount >= this.maxWords) {
                this.$field.value = value.match(regex);
            }

            var wordsLeft = this.maxWords - wordCount;

            if (wordsLeft <= 0) {
                wordsLeft = '0';
            }

            this.$text.innerHTML = t('{num} words left', {
                num: wordsLeft,
            });
        }, 1);
    }
}

window.FormieTextLimit = FormieTextLimit;
