<!-- This file handles the Brands page actions on the client side.-->

<template>
  <DynamicPage @getList="getList" :name_singular="name_singular" :name_plural="name_plural" :list_data="list_data" :form_fields="form_fields" :api_url="api_url"></DynamicPage>
</template>
<script>
import moment from 'moment'
import DynamicPage from '../components/dynamicpage/DynamicPage.vue'
var qs = require('qs');
export default {
  name: 'IRMBBrands',
  components: {
    DynamicPage
  },
  data: () =>{
    return{
      //set the name of the page both in singular format and plural format
      name_singular:"Brand",
      name_plural:"Brands",
      //list of data to be shown on the DataListComponent
      list_data : [],
      //set your fields here
      form_fields:[
        {key: "id", field_key:"id", label:"id", field_description: "id" ,type:"Hidden"},
        {key: "brand_name", field_key:"name", label:"Brand Name", field_description: "The name of the Brand" ,type:"Text"},
        {key: "client", field_key:"client_id", label:"Client", field_description: "To which Client does this invoice apply?" , list:"client_opts", dependent:"false", type:"Dropdown"},
        {key: "commission", field_key:"commission", label:"Commission", field_description: "Select a commission rate for this Brand" , list:"commission_opts", dependent:"true", dependent_field:"client_id", type:"Dropdown"},
        {key: "description_field", field_key:"description", label:"Description", field_description: "" ,type:"Textarea", placeholder:"Please enter any notes or description here ..."},
      ],
      api_url: '/brand'
    }
  },
  mounted() {
	  this.getList()
  },
  methods :{
    getList() {
      this.$http.get('/brand').then((r) => {
				this.list_data = r.data
			})
    },

  }
}
</script>

<style scoped>
  .btn.disabled {
    cursor: auto;
  }
	.horizontal-scroll {
		overflow-x: scroll;
	}

	.row-eq-height {
		display: -webkit-box;
		display: -webkit-flex;
		display: -ms-flexbox;
		display:         flex;
	}

	.collapsed > .when-opened,
		:not(.collapsed) > .when-closed {
		display: none;
	}
</style>


