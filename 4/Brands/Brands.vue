<template>
  <div class="animated fadeIn">
	<b-row>
		<b-col cols="4">
  		<b-card>
  			<b-card-text><h5>Brands</h5></b-card-text>
  			<b-card-text v-for="(row, row_idx) in list_data" :key="row.id">
  				<i class="fa fa-edit" v-if="selected_record.id == row.id"></i>
  				<a @click.preventDefault="handleSelect" :id="row_idx">{{row.client_name}}&nbsp;&nbsp;&nbsp;{{row.name}}</a>
  			</b-card-text>
  			<b-card-text v-if="list_length == 0">No records found</b-card-text>
  		</b-card>
		</b-col>
		<b-col cols="8">
  		<b-card>
  			<b-card-text><h5>View, Create or Edit Brands</h5>To create a brand, just add the details or click Clear Form. To update an existing brand, please select from the list on the left.</b-card-text>
  			<b-card-text>
          <ValidationObserver v-slot="{ handleSubmit,reset}">
  				<b-form-group
  					id="fieldset-name"
  					label-cols-sm="4"
  					label-cols-lg="3"
  					description="The name of the Brand"
  					label="Brand Name"
  					label-for="name"
  					>
            <validation-provider rules="required" v-slot="{ errors }">
              <span style="font-size:10px;color:red;font-style: italic;">{{ errors[0] }}</span>
              <b-form-input id="name" v-model="selected_record.name"></b-form-input>
            </validation-provider>
  				</b-form-group>

  				<b-form-group
  					label-cols-sm="4"
  					label-cols-lg="3"
  					v-if="!selected_record.client_id || !selected_record.client_name"
  					label="Client"
  					description="To which Client does this brand apply? TODO: MAKE THIS A DROPDOWN"
  					>
  					<b-form-select v-if="!selected_record.client_id || !selected_record.client_name" v-model="selected_record.client_id" :options="client_list"></b-form-select>
  				</b-form-group>
  				<b-form-group
  					label-cols-sm="4"
  					label-cols-lg="3"
  					v-else
  					label="Client"
  					>
  					<a href="#">{{selected_record.client_name}}</a>
  				</b-form-group>


          <b-form-group
  					id="fieldset-commission"
  					label-cols-sm="4"
  					label-cols-lg="3"
  					description="Select a commission rate for this Brand"
  					label="Commission"
  					label-for="commission"
  					>
  					<b-form-select v-model="selected_record.commission" :options="commission_opts"></b-form-select>
  				</b-form-group>
  				<b-form-group
  					id="fieldset-description"
  					label-cols-sm="4"
  					label-cols-lg="3"
  					description=""
  					label="Description"
  					label-for="description"
  					>
  					<b-form-textarea placeholder="Please enter any notes or description here ..." id="description" v-model="selected_record.description"></b-form-textarea>
  				</b-form-group>
          <b-button type="submit" variant="primary" v-if="selected_record.id" @click.preventDefault="handleSubmit(saveForm)">Update</b-button>
          <b-button type="submit" variant="primary" v-else @click.preventDefault="handleSubmit(saveForm)">Create</b-button>
          <b-button type="submit" variant="success" @click.preventDefault="reset(clearForm)" >Refresh Form</b-button>
        </ValidationObserver>
        <b-button type="submit" variant="secondary" @click.preventDefault="clearForm" >Clear Form</b-button>
        </ValidationObserver>
          <DeleteButtonComponent :disabled="selected_record.id"  @deleteConfirmed="deleteConfirmed" @deleteDeclined="deleteDeclined" :name="this.pageName"></DeleteButtonComponent>
  			</b-card-text>
  		</b-card>
		</b-col>
	</b-row>
  </div>
</template>

<script>
import moment from 'moment'
import DeleteButtonComponent from '../components/buttons/DeleteButtonComponent.vue'
import { ValidationProvider, ValidationObserver, extend } from 'vee-validate';
import { required } from 'vee-validate/dist/rules';
var qs = require('qs');

extend('required', {
  ...required,
  message: 'This field is required'
});

export default {
  name: 'IRMBBrands',
  components: {
    DeleteButtonComponent,ValidationProvider,ValidationObserver
  },
  mounted() {
	  this.clearForm()
	  this.getList()
	  this.getClientList()
  },
  filters: {
	},
	computed: {
		list_length: function() {
			return this.list_data.length
		},
		client_id: function() {
			return this.selected_record.client_id
		},
		selected_client: function() {
			let $this = this
			var a = this.client_list.filter(function(o) {
				return o.value===$this.client_id
			})

			return a.length==1 ? a[0] : {}
		},
	},
  watch: {
		client_id: function (v, i) {
			this.selected_record.commission = !this.selected_record.commission && this.selected_client.hasOwnProperty('commission') ? this.selected_client.commission : this.selected_record.commission;
		},
  },
  data: () => {
    return {
    pageName:"Brand",
		list_data: [],
		selected_record: [],
		client_list: [],
    commission_opts:[
      {value:'12.5000', text:'12.50 %'},
      {value:'14.0000', text:'14.00 %'},
      {value:'15.0000', text:'15.00 %'},
    ],
	}


  },
  methods: {
		handleSelect(e) {
			this.selected_record = this.list_data[e.srcElement.id]
		},
		clearForm(e) {
			this.selected_record = {
				id: '',
				client_id: '',
				name: '',
				description: '',
				commission: '',
			}
		},
		saveForm( e ) {
			this.$http.post('/brand', this.selected_record).then((r) => {
				this.getList()
				this.clearForm()
			})
		},
		getList() {
			this.$http.get('/brand').then((r) => {
				this.list_data = r.data
			})
		},
		getClientList() {
			this.$http.get('/client').then((r) => {
				this.client_list = r.data.map(function(v, i, a) {
					return {value:v.id, text:v.name, commission:v.commission}
				});

				//console.log(this.client_list)
			})
		},
    deleteConfirmed: function() {
  		this.isLoading = true;
  		this.$http.get('/brand/'+encodeURI(this.selected_record.id) + '/delete').then((r) => {
        this.getList()
        this.clearForm()
      });
  	},
    deleteDeclined: function() {
      console.log("Delete Declined");
  	},
  },
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

<!-- <template>
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
</script> -->
