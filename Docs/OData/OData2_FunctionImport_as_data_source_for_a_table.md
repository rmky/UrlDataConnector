# OData FunctionImport as data source for a table

To fill a data widget (e.g. a `DataTable`) with the result of a FunctionImport, the `lazy_loading_action` of the widget can be replaced by the action `exface.UrlDataConnector.CallOData2Operation`.

Concider the following example, where the function import `MY_DATA_LOADER` returns the OData entity `FUNC_OBJECT` based on some internal logic taking two parameters: `param1` and `param2`. Since we are going to display the results in a `DataTable` these parameters act as filters while attributes of the meta object can be used as columns.

Here is what we need to do:

1) Add attributes to the meta object `my.App.FUNC_OBJECT` for every parameter of the FunctionImport with
	- the parameter name as alias (not required, but handy)
	- no data address
	- marked as filterable, but not readable, writable or anything else
2) Create a data widget with our FunctionImport as custom `lazy_loading_action` - see below
4) Configure an `input_mapper` for the action to map filter values to input data columns for the action. This is important as the FunctionImport takes column values as parameters, not filters!
3) Give the data table filters with the desired parameters as `attribute_alias`.

```
{
	"widget_type": "DataTable",
	"object_alias": "my.App.FUNC_OBJECT",
	"lazy_loading_action": {
		"alias": "exface.UrlDataConnector.CallOData2Operation",
		"function_import_name": "MY_DATA_LOADER",
		"result_object_alias": "my.App.FUNC_OBJECT",
		"parameters": [
			{"name": "param1"},
			{"name": "param2"}
		],
		"input_mapper": {
          "filter_to_column_mappings": [
            	{
            		"from": "param1",
               	"to": "param1",
               	"to_single_row": true,
               	"to_single_row_separator": ","
           	}
         	]
     	}
	},
	"filters": [
		{"attribute_alias": "param1"},
		{"attribute_alias": "param1"}
	]
}
```

Of course, in a real scenario the action config should be saved in the metamodel as an object action.

Since FunctionImports cannot take tabular data as input parameters, the `input_mapper` must press all filter values into a single input data row. This is done via `to_single_row` option. This makes sure, the mapper works even if we have multi-select filters.

If we do not force all values into a single row, the input data for the FunctionImport will have multiple rows, which will cause multiple requests to the OData Service. It should still work, but will probably be conciderably less efficient. Only use this if the FunctionImport does not understand delimited lists in it's parameters.

**NOTE**: Since the data is not fetched via standard reading-actions, no relations can be used in alias expressions. Technically, we just make the table display the result of some server-action - no joining data source, no in-memory aggregation, etc.