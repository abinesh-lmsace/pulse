define('pulseaddon_reaction/reaction', [], function () {
    return {
        // Update the reaction visibilty based on the activity completion selection.
        update_reactions: function () {
            var reactionselector = document.getElementById('id_options_reactiontype');
            var completionselfselector = document.getElementById('id_completionself');
            var completionapprovalselector = document.getElementById('id_completionapproval');
            // Disable the Mark completion reaction when the completion self condition not selected.
            if (completionselfselector.checked) {
                reactionselector.querySelector('option[value="1"]').removeAttribute('disabled');
            } else {
                reactionselector.querySelector('option[value="1"]').setAttribute('disabled', true);
            }
            // Disable the approval reaction when the approval completion condition not selected.
            if (completionapprovalselector.checked) {
                reactionselector.querySelector('option[value="3"]').removeAttribute('disabled');
            } else {
                reactionselector.querySelector('option[value="3"]').setAttribute('disabled', true);
            }
        },

        init: function () {
            var completiongroup = document.getElementById('id_activitycompletionheader');
            if (completiongroup !== null) {
                var conditions = completiongroup.querySelectorAll('input[type=checkbox]');
                // Update the reactions.
                this.update_reactions();
                var module = this;
                for (var i = 0; i < conditions.length; i++) {
                    conditions[i].addEventListener('change', function () {
                        module.update_reactions();
                    });
                }
            }
        }
    };
});
