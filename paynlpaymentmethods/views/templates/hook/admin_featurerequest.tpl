<div class="panel" id="feature_request">
    <div class="panel-heading">
        <i class="icon-envelope"></i> {l s='Feature request' mod='paynlpaymentmethods'}
    </div>  
    <div class="panel">
        <p>{l s='If you have a feature request or other ideas, let us know!' mod='paynlpaymentmethods'}</p>
        <p>{l s='Your submission will be reviewed by our development team.' mod='paynlpaymentmethods'}</p>
        <p>{l s='If needed, we will contact you for further information via the e-mail address provided.' mod='paynlpaymentmethods'}</p>
        <p>{l s='Please note: this form is not for Support requests, please contact support@pay.nl for this.' mod='paynlpaymentmethods'}</p>
    </div>
    <br/>
    <div class="panel">
    <form>   
        <input type="hidden" id="pay-ajaxurl" value="{$ajaxURL}">      
        <div class="form-group">
            <label class="control-label col-lg-1 align-right">{l s='Email' mod='paynlpaymentmethods'}</label>
            <div class="col-lg-11">
                <input style="width:100%;" type="text" name="FR_Email" id="FR_Email">       
                <p class="help-block"></p>      
            </div>
        </div>       
        <div class="form-group">
            <span id="message_error" style="padding: 15px;color: red;font-size: 12px; display:none;">Please fill in a message.</span>
            <label class="control-label col-lg-1 align-right required">{l s='Message' mod='paynlpaymentmethods'}</label>
            <div class="col-lg-11">
                <textarea  style="height:250px;" id="FR_Message" name="FR_Message" placeholder="{l s='Leave your suggestions here...' mod='paynlpaymentmethods'}"></textarea>
                <p class="help-block"></p>              
            </div>
        </div>    
    </form>     
    <div style="clear:both;"></div>
    </div>   
    <div class="panel-footer" style="margin-top:20px;">        
        <button onclick="submitFeatureRequestForm()" type="submit" value="1" id="module_form_submit_btn_fr" name="btnSubmitFR"
                class="btn btn-default pull-right">
            <i class="process-icon-mail"></i> {l s='Send'}
        </button>
    </div>
</div>
<div id="test"></div>

<script type="text/javascript">     
    function submitFeatureRequestForm() {
        $('#message_error').hide();
        var email = $('#FR_Email').val();
        var message = $('#FR_Message').val();   
        if($.trim(message) == ''){
            $('#message_error').css('display', 'inline');
            return false;
        }
        var ajaxurl = $('#pay-ajaxurl').val();        
        var data = {
            'email' : email,
            'message' : message,
            'calltype' : 'feature_request'
        };     
        setTimeout(function () {
            $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: data,
                    dataType: 'json',
                    success: function (data) {
                        if (data.success) {
                            $('#FR_Email').val("");
                            $('#FR_Message').val("");
                            alert('Sent! Thank you for your contribution.');
                        } else {
                            alert('Couldn\'t send email.');
                        }
                    },
                    error: function () {                        
                        alert('Couldn\'t send email.');
                    }
                });
            }, 750);
        
    };
</script>