kodReady.push(function(){
	LNG.set(jsonDecode(urlDecode("{{LNG}}")));
	if($.hasKey('plugin.{{package.id}}.style')) return;
	requireAsync("{{pluginHost}}static/page.css?v={{package.version}}");
});
