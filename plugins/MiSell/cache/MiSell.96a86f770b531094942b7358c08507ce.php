<?php if(!class_exists('raintpl')){exit;}?><!DOCTYPE html> 
<html>
<head>
<title>Mobile iSell</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style type="text/css">
.ui-filter-inset {
    margin-top: 0;
}
body{
    -moz-user-select:none;
    -webkit-user-select:none;
}
/* Listview with collapsible list items. */
.ui-listview > li .ui-collapsible-heading {
  margin: 0px;
}
.ui-collapsible.ui-li-static {
  padding: 0px;
  border: none !important;
}
#right-panel .ui-listview > .ui-li-static {
    padding: 0px !important;
}
/* Nested list button colors */
.ui-listview .ui-listview .ui-btn {
    font-weight: normal;
    font-size: 0.8em;
}
</style>
<link rel="stylesheet" href="http://code.jquery.com/mobile/1.4.0/jquery.mobile-1.4.0.min.css" />
<script src="http://code.jquery.com/jquery-1.9.1.min.js"></script>
<script src="http://code.jquery.com/mobile/1.4.0/jquery.mobile-1.4.0.min.js"></script>
<script type="text/javascript">
$( document ).on( "pageinit", "#homePage", function() {
    $("#clientSelect").on("click", function(e){
        clientSelect(e.target.getAttribute('data-client-id'));
    });
});
$( document ).on( "pageinit", "#orderPage", function() {
    stockCatSelect(_selectedStockCat);
    $( "#autocomplete" ).on( "filterablebeforefilter", function ( e, data ) {
        var $input = $( data.input ),
            value = $input.val();
        $( "#autocomplete" ).html( "" );
        if ( value && value.length > 1 ) {
            loadSuggestions( $input.val() );
        }
    });
    $( "#autocomplete" ).on( "click", function ( e ){
        orderSuggClick(e.target.getAttribute('data-product-code'));
    });
    $( document ).on( "swiperight", "#orderPage", function( e ) {
        if ( $( ".ui-page-active" ).jqmData( "panel" ) !== "open" ) {
            if ( e.type === "swipeleft" ) {
                $( "#right-panel" ).panel( "open" );
            }
        }
    });
});

//////////////////
//CUSTOM FUNCTIONS
//////////////////
App={
    json:function(text){
        try{
            if(text!==''){//Allow empty response
                return JSON.parse(text);
            }
        }
        catch(e){
            console.log(e);
        }	
	return {};
    }
};
var _selected_client={},
    _orderCache={},
    _current_entry={},
    _suggest_list={},
    _selectedStockCat=0;
///////////////////////////////
//CLIENT HANDLING
///////////////////////////////
function clientSelect(client_id){
    $.each(db.companies_tree,function(key,tree_folder){
        $.each(tree_folder,function(i,company){
            if(client_id===company.company_id){
                _selected_client=company;
                clientShow();
                return;
            }
        });
    });
    $("#clientSelect" ).popup( "close" );
}
function clientShow(){
    var html="";
    html+="<li style='white-space:normal'><b>Контакт: </b>"+(_selected_client.company_person||'')+"</li>";
    html+="<li style='white-space:normal'><b>Мобильный: </b>"+(_selected_client.company_mobile||'')+"</li>";
    html+="<li style='white-space:normal'><b>Адрес: </b>"+(_selected_client.company_address||'')+"</li>";
    html+="<li style='white-space:normal'><b>Заметки: </b>"+(_selected_client.company_description||'')+"</li>";
    $("#clientdetails").html(html);
    $("#clientdetails").listview("refresh");
    $("#clientdetails").trigger( "updatelayout");
    $("#clientdetailsBlock").show();
    $("#clientSelectButton").html(_selected_client.label);
}
//////////////////////////
//ORDER HANDLING
//////////////////////////
function loadSuggestions( q ){
    var $ul=$( "#autocomplete" );
    $ul.html( "<li><div class='ui-loader'><span class='ui-icon ui-icon-loading'></span></div></li>" );
    $ul.listview( "refresh" );
    $.get("./suggest",{q:q,parent_id:_selectedStockCat,company_id:_selected_client.company_id},function(response){
	var suggested=App.json(response);
        _suggest_list={}
        var html = "";
        $.each( suggested, function ( i, val ) {
            _suggest_list[val.code]=val;
            html+="<li style='color:"+(val.instock==1?"green":"red")+"'";
            html+="data-product-code='"+val.code+"'>"
            html+="<div style='display:inline-block;width:5em;text-align:right;color:#999'>"+val.code+"</div>";
            html+=" "+val.name+" <b>"+val.price+"</b> </li>";
        });
        $ul.html( html );
        $ul.listview( "refresh" );
        $ul.trigger( "updatelayout");	
    });
}
function stockCatSelect(branch_id){
    $("#stockBranch"+_selectedStockCat).removeClass("ui-btn-active");
    $("#stockBranch"+branch_id).addClass("ui-btn-active");;
    _selectedStockCat=branch_id;
    loadSuggestions();
}
function orderAdd(){
    $("#qtyPopup").popup( "close" );
    var qty=1*$("#qtyInput").val();
    if( _orderCache[_current_entry.code] && _current_entry.mode!=='edit' ){
        _current_entry.qty=qty+_orderCache[_current_entry.code].qty;
    }
    else{
        _current_entry.qty=qty;
    }
    _orderCache[_current_entry.code]=_current_entry;
    orderShow();
}
function orderSuggClick(pcode){
    _current_entry=_suggest_list[pcode];
    $("#qtyPopup").popup( "open" );
    $("#qtyInput").val(_current_entry.spack);
    $("#qtyPopupHeader").html(_current_entry.name);
    $("#qtyInput").select();
}
function orderQtyEdit(pcode){
    _current_entry=_orderCache[pcode];
    _current_entry.mode='edit';
    $("#qtyPopup").popup( "open" );
    $("#qtyInput").val(_current_entry.qty);
    $("#qtyPopupHeader").html(_current_entry.name);
    $("#qtyInput").select();
}
function orderDelete(pcode){
    delete _orderCache[pcode];
    orderShow();
}
function orderSend(){
    if(!_selected_client.company_id){
        $("#orderServerInteraction").html("<p>Не выбран клиент!</p>");
        $("#orderServerInteraction").popup("open");
        return;
    }
    $("#orderServerInteraction").html("<p>Заказ отправляется...</p>");
    $("#orderServerInteraction").popup("open");
    var company_id=_selected_client.company_id;
    $.post("./orderSend",{order:JSON.stringify(_orderCache),company_id:company_id},function(resp){
        $("#orderServerInteraction").html("<p>Заказ доставлен на сервер!</p>");
	setTimeout(function(){$("#orderServerInteraction").popup("close");},1000);
        _orderCache=[];
        orderShow();
    });
}
function format(num){
   var parts=(Math.round(num*100)/100+'').split('.');
   if(parts[0]&&parts[1]){
        parts[1]+=parts[1]?(parts[1].length===1?'0':''):'00';
        return parts.join('.');
    }
    return NaN;
}
function orderShow(){
    var html="",i=0,sum=0;
    $.each( _orderCache, function ( pcode, entry ) {
        html+='<li>';
        html+='<span style="width:60%;display:inline-block;overflow:hidden;"><a href="#" onclick="orderDelete('+pcode+')" style="text-decoration:none;color:red;">X</a> '+pcode+'</span>';
        html+='<span style="width:20%;display:inline-block;overflow:hidden;"><a href="#" onclick="orderQtyEdit('+pcode+')">'+entry.qty+'</a>'+entry.unit+'</span>';
        html+='<span style="width:20%;display:inline-block;overflow:hidden;">'+(format(entry.price)||'?')+'</span>';
        html+='<br>'+(++i)+'. <i>'+entry.name+'</i></li>';
        sum+=entry.price*entry.qty;
    });
    $("#orderlist").html(html);
    $("#orderlist").listview("refresh");
    $("#orderlist").trigger( "updatelayout");
    $("#orderSummary").html("Сумма: "+(format(sum)||'?')+"");
}
function logout(){
    Connector.sendRequest({mod:'Login',rq:'logout'},function(){
       location.reload();
    });
}
var db=<?php echo $db;?>;
</script>
</head>

<body>
<div data-role="page" id="homePage">
    <div data-role="header" data-theme="b">
        <h1>iSell Mobile</h1>
    </div>
    <div data-role="main" class="ui-content">
        <h4><p>Добро пожаловать, <?php echo $d["user_sign"];?></p></h4>
        <div class="ui-corner-all custom-corners">
          <div class="ui-bar ui-bar-a">
            <h3>Работа с клиентами</h3>
          </div>
          <div class="ui-body ui-body-a">
          <p>
            <a href="#clientSelect" id="clientSelectButton" data-rel="popup" class="ui-btn ui-corner-all ui-shadow ui-icon-user ui-btn-icon-left ui-btn-b" data-transition="pop">Клиент не выбран</a>
            <div data-role="popup" id="clientSelect" data-theme="none">
                <div data-role="collapsible-set" data-theme="b" data-content-theme="a" data-collapsed-icon="arrow-r" data-expanded-icon="arrow-d" style="margin:0; width:250px;">
                    <?php $counter1=-1; if( isset($d["companies_tree"]) && is_array($d["companies_tree"]) && sizeof($d["companies_tree"]) ) foreach( $d["companies_tree"] as $key1 => $value1 ){ $counter1++; ?>
                    <div data-role="collapsible" data-inset="false">
                    <h2><?php echo $key1;?></h2>
                    <ul data-role="listview">
                        <?php $counter2=-1; if( isset($value1) && is_array($value1) && sizeof($value1) ) foreach( $value1 as $key2 => $value2 ){ $counter2++; ?>
                        <li>
                            <a href="#" data-rel="close" data-client-id="<?php echo $value2->company_id;?>">
                                <?php echo $value2->label;?>
                            </a>
                        </li>
                        <?php } ?>
                    </ul>
                    </div>
                    <?php } ?>
                </div>
            </div>
            <div id="clientdetailsBlock" style="display:none">
                <ul id="clientdetails" data-role="listview" data-theme="a" style="margin-bottom:3px;"></ul>
                <a href="#orderPage" class="ui-btn ui-corner-all ui-shadow ui-btn-b">Принять заказ</a>
            </div>
          </p>
          </div>
        </div>
        <a href="#" onclick="logout()" class="ui-btn ui-btn-icon-left ui-icon-power">Выход</a>
    </div>
</div>
<!-- 

ORDER PAGE

-->
<div data-role="page" id="orderPage">
    <div data-role="header" data-theme="b">
        <h1>Заказ</h1>
        <a href="#homePage" class="ui-btn ui-shadow ui-corner-all ui-icon-home ui-btn-icon-left">Домой</a>
        <a href="#right-panel" class="ui-btn ui-shadow ui-corner-all ui-btn-right ui-icon-bars ui-btn-icon-left">Меню</a>
    </div><!-- /header -->
    <div data-role="main" class="ui-content">
        <div id="orderServerInteraction" data-role="popup" data-dismissible="true" data-overlay-theme="b" data-theme="b"></div>
        <div id="alert" data-role="popup" data-overlay-theme="b" data-theme="b"></div>
        <!--QUANTITY DIALOG-->
        <div data-role="popup" id="qtyPopup" data-overlay-theme="b" data-position-to="window" data-theme="a" data-dismissible="true" style="-max-width:400px;">
            <div data-role="header" data-theme="b">
                <h1>Колличество</h1>
            </div>
            <div role="main" class="ui-content" style="text-align: center">
                <h6 class="ui-title" id="qtyPopupHeader"></h6>
                <form onsubmit="orderAdd();return false">
                <table>
                    <tr>
                        <td><a href="#" class="ui-btn ui-btn-inline ui-btn-b" onclick="$('#qtyInput').val($('#qtyInput').val()*_current_entry.spack)">уп X</a></td>
                        <td><input data-clear-btn="true" pattern="[0-9]*" id="qtyInput" value="1" type="number"></td>
			<td><a href="#" class="ui-btn ui-corner-all ui-shadow ui-btn-inline ui-btn-b" onclick="orderAdd()">OK</a></td>
                    </tr>
                </table>
                </form>
            </div>
        </div>
        <!--ORDER LIST-->

        <ul id="orderlist"
            data-role="listview"
            data-theme="a"
            style="margin-bottom:10px;">
        </ul>
        <div id="orderSummary" style="text-align: right"></div>
        <!--AUTOSUGGEST-->
        <ul id="autocomplete"
            data-role="listview" 
            data-inset="false" 
            data-filter="true" 
            data-filter-theme="a"
            data-filter-placeholder="Поиск товаров..."
            data-transition="pop"
            >
        </ul>
    </div>
   <!-- /content -->
    <div id="right-panel" data-role="panel" data-position="right" data-theme="b" data-display="push">
        <ul data-role="listview">
            <li><a href="#" onclick="orderSend()" data-rel="close" class="ui-btn ui-btn-inline ui-shadow ui-corner-all ui-btn-icon-left ui-icon-mail">Отправить</a></li>
            <li data-role="list-divider">Категории товара</li>
            <ul id="stockCatTree" data-role="listview">
                <li><a href="#right-panel" data-rel="close" id="stockBranch0" onclick="stockCatSelect(0)">Все</a></li>
                <?php $counter1=-1; if( isset($d["stock_tree"]["children"]) && is_array($d["stock_tree"]["children"]) && sizeof($d["stock_tree"]["children"]) ) foreach( $d["stock_tree"]["children"] as $key1 => $value1 ){ $counter1++; ?>
                <?php if( count($value1["children"]) ){ ?>
                <li data-role="collapsible" data-inset="false" data-iconpos="right">
                    <h3><b><?php echo $value1["label"];?></b></h3>
                    <ul data-role="listview">
                        <?php $counter2=-1; if( isset($value1["children"]) && is_array($value1["children"]) && sizeof($value1["children"]) ) foreach( $value1["children"] as $key2 => $value2 ){ $counter2++; ?>
                        <li><a href="#right-panel"
                               data-rel="close"
                               onclick="stockCatSelect('<?php echo $value2["branch_id"];?>')"
                               id="stockBranch<?php echo $value2["branch_id"];?>"
                               style="padding-left:25px;">
                               <?php echo $value2["label"];?>
                            </a>
                        </li>
                        <?php } ?>
                    </ul>
                </li>
                <?php }else{ ?>
                <li><a href="#right-panel"
                       data-rel="close"
                       onclick="stockCatSelect('<?php echo $value1["branch_id"];?>')"
                       id="stockBranch<?php echo $value1["branch_id"];?>">
                       <?php echo $value1["label"];?>
                    </a>
                </li>
                <?php } ?>
                <?php } ?>
            </ul>
        </ul>
    </div><!-- /panel -->
</div>
</body>
</html>